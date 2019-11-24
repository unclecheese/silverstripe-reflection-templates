<?php

/**
 * Introspects a SS template, working similar to PHP's {@link ReflectionClass},
 * providing an API for collecting all the variables and blocks.
 *
 * Attempts to infer what field type each variable might be based on context
 * and methods that are invoked against them. Identifies any variables that may
 * be a custom has_one relation.
 *
 * Excludes any variables that are globally available to all templates, or
 * in the the case of SiteTree or Email, any variables that are made available
 * to those templates without customisation, e.g. $Menu, $Top, $ID, etc.
 *
 * This utility is very experimental and highly dependent on naming conventions,
 * especially that template methods are always UpperCamelCase.
 * 
 * <code>
 * $reflector = ReflectionTemplate::create();
 * $reflector->process(file_get_contents('/path/to/template.ss'));
 * 
 * foreach($reflector->getTopLevelVars() as $varName => $type) {
 * 	echo "The template variable $varName is likely a $type\n";
 * }
 * 
 * foreach($reflector->getBlocks() as $block) {
 * 	 echo "There is a block named {$block->getName()}\n";
 * 	 echo $block->isLoop() ? "This block is a loop\n" : "This block is a with\n";
 * 	 foreach($block->getVars() as $var => $type) {
 * 		echo "The block contains a variable named $var that is likely a $type\n";
 * 		foreach($block->getChildren() as $child) {
 * 			echo "There is a child block named {$child->getName()}. It has the following vars:\n";
 * 			foreach($child->getVars() as $v => $t) {
 * 				// etc ... 
 * 			}
 * 		}
 * 	  }	 	
 * }
 * </code>
 *
 * @author  Uncle Cheese <unclecheese@leftandmain.com>
 * @package  silverstripe-reflection-templates
 */
class ReflectionTemplate extends SS_Object {
	
	/**
	 * A computed list of all the core template accessors, e.g. $Up, $Top
	 * @var array
	 */
	protected $templateAccessors;
	
	/**
	 * A computed list of all the functions available to {@link DBField} classes
	 * @var array
	 */
	protected $dbfieldFunctions;

	/**
	 * A computed list of all the functions available to {@link SS_List} instances
	 * @var array
	 */
	protected $listFunctions;

	/**
	 * The code being analysed
	 * @var string
	 */
	protected $code;

	/**
	 * The list of blocks in the template, indexed by their strpos
	 * @var array
	 */
	protected $blockManifest = array ();
	
	/**
	 * A reference to the $Top block
	 * @var ReflectionTemplate_Block
	 */
	protected $topBlock;
			
	/**
	 * Gets all the template accessors, looking at the {@link TemplateGlobalProvider} implementors
	 * and caches the result.
	 * @return array
	 */
	public function getTemplateAccessors() {
		if($this->templateAccessors) return $this->templateAccessors;

		$list = array ('Up', 'Top');
		foreach(ClassInfo::implementorsOf('TemplateGlobalProvider') as $class) {
			$vars = $class::get_template_global_variables();
			if($vars) {
				foreach($vars as $varName => $func) {
					$list[] = is_numeric($varName) ? strtolower($func) : strtolower($varName);
				}
			}
		}

		return $this->templateAccessors = $list;		
	}

	/**
	 * Gets all methods that are defined on {@link DBField} instances and caches the result
	 *
	 * @todo  This gets really ugly with some hardcoded opinions. Needs rethinking.
	 * @return array
	 */
	public function getDBFieldFunctions() {
		if($this->dbfieldFunctions) return $this->dbfieldFunctions;

		foreach(ClassInfo::subclassesFor("DBField") as $class) {
			$r = new ReflectionClass($class);
			if($methods = $r->getMethods()) {
				foreach($methods as $m) {
					$name = $m->getName();
					if(preg_match("/[A-Z]/",$name[0])) {
						$this->dbfieldFunctions[strtolower($name)] = $class;
					}						
				}
			}
		}
		// Give a good default for "Nice"
		$this->dbfieldFunctions["Nice"] = "Date";		

		$r = new ReflectionClass("Image");
		if($methods = $r->getMethods()) {
			foreach($methods as $m) {
				$name = $m->getName();
				if(substr($name,0,8) == "generate") {
					$this->dbfieldFunctions[strtolower(substr($name,8))] = "Image";
				}						
			}
		}
		$r = new ReflectionClass("File");
		if($methods = $r->getMethods()) {
			foreach($methods as $m) {
				$name = $m->getName();
				if(substr($name,0,3) == "get") {
					$prop = substr($name,3);
					if(!in_array($prop, array('Title','Name','ID','Parent'))) {
						$this->dbfieldFunctions[strtolower(substr($name,3))] = "File";
					}
				}						
			}
		}

		return $this->dbfieldFunctions;
	}

	/**
	 * Gets all the methods available to {@link SS_List} instances and caches the result.
	 * @return array
	 */
	public function getListFunctions() {
		if($this->listFunctions) return $this->listFunctions;

		$list = array ();
		foreach(ClassInfo::implementorsOf('SS_List') as $class) {
			$r = new ReflectionClass($class);			
			$methods = $r->getMethods();
			if($methods) {
				foreach($methods as $m) {
					$name = $m->getName();					
					if(preg_match("/[A-Z]/",$name[0])) {
						$list[] = strtolower($name);
					}											
				}
			}
		}

		return $this->listFunctions = $list;
	}

	/**
	 * Given a variable name, figure out what type it might be. This can be guided
	 * via the {@link Config} layer
	 * @param  string $name The variable name
	 * @return string
	 */
	public function inferDatatype($name) {
		foreach($this->config()->infer_datatype as $match => $type) {
			if(strstr($name, $match) !== false) {
				return $type;
			}
		}

		return $this->config()->default_datatype;
	}
		
	/**
	 * Does all the heavy lifting of processing the template, getting all the vars
	 * and indexing the blocks by their strpos(), and assigning their contents to
	 * {@link ReflectionTemplate_Block} instances.
	 * 
	 * @param  string $code The template code to analyse	 
	 */
	public function process($code) {
		// Flatten out the code so the tabs and newlines don't get in the way
		$this->code = str_replace(
			array("\n","\r","\t"), 
			array("","",""),
			$code
		);		

		$blockList = array ();
		$blockIndex = array ();

		preg_match_all("/<% (loop|with) (.*?) %>/", $this->code,  $openBlocks, PREG_OFFSET_CAPTURE);
		preg_match_all("/<% end_(loop|with) %>/", $this->code, $closeBlocks, PREG_OFFSET_CAPTURE);
		
		if(!$openBlocks || !$closeBlocks) return;

		$openBlocks = reset($openBlocks);
		$closeBlocks = reset($closeBlocks);
		
		// If there aren't the same about of opening delimiters as close delimiters,
		// this is a malformed template
		if(count($openBlocks) != count($closeBlocks)) {
			throw new ValidationException("Template is malformed. Open loops and closed loops are mismatched");
		}

		$loops = array_merge($openBlocks, $closeBlocks);
		
		// Index all the mactches by their offset, e.g. strpos
		foreach($loops as $match) {
			$blockList[$match[1]] = $match[0];	
		}
		ksort($blockList, SORT_NUMERIC);


		$openBlocks = array();

		// Now that each block has a unique identifier (its offset), create an index
		// that allows lookups of its parent block and where it ends
		foreach($blockList as $pos => $block) {
			if($block != "<% end_loop %>" && $block != "<% end_with %>") {
				$blockIndex[$pos] = array (
					'parent' => empty($openBlocks) ? 0 : end($openBlocks)
				);
				array_push($openBlocks, $pos);	
			}
			else {
				$opener = array_pop($openBlocks);
				$blockIndex[$opener]['end'] = $pos;
			}
		}

		// Lopp through the index to create ReflectionTemplate_Block instances for each.
		foreach($blockIndex as $pos => $data) {
			$block = new ReflectionTemplate_Block($this, $pos, $data['end'], $data['parent']);
			if(!in_array(strtolower($block->getName()), $this->getTemplateAccessors())) {
				$this->blockManifest[$pos] = $block;
				if($data['parent'] > 0) {
					$this->getBlockByID($data['parent'])->addChild($pos);
				}
			}
		}				

		// Generate a top level block, consisting of all the vars and conditions in the top scope.
		// Strips out all the contents of every known block, and what you're left with is the
		// "root" template
		$originalCode = $this->code;
		foreach($this->getBlocks() as $block) {
			$this->code = str_replace($block->getOuterContents(), "", $this->code);
		}
		$top = new ReflectionTemplate_Block($this);
		$this->topBlock = $top;
		$this->code = $originalCode;
	}
	
	/**
	 * Gets a block by its identifier, or offset, in the template string
	 * @param  int $pos The block offset
	 * @return ReflectionTemplate_Block
	 */
	public function getBlockByID($pos) {
		if(isset($this->blockManifest[$pos])) {
			return $this->blockManifest[$pos];
		}
	}
	
	/**
	 * Gets a block by its name, e.g. <% loop $Items %> is named "Items"
	 * Not very reliable, as there may be multiple blocks with the same name.
	 * @param  string $name 
	 * @return ReflectionTemplate_Block
	 */
	public function getBlockByName($name) {
		foreach($this->blockManifest as $block) {
			if($block->getName() == $name) {
				return $block;
			}
		}
		return false;
	}
	
	/**
	 * Gets all the blocks in the template, including those that are nested
	 * @return array
	 */
	public function getBlocks() {
		return $this->blockManifest;
	}

	/**
	 * Gets all the blocks that are <% loop %>
	 * @return array
	 */
	public function getLoops() {
		$ret = array ();
		foreach($this->blockManifest as $block) {
			if($block->isLoop()) {
				$ret[] = $block;
			}
		}

		return $ret;
	}
	
	/**
	 * Gets all the blocks that are <% with %>
	 * @return array
	 */
	public function getWiths() {
		$ret = array ();
		foreach($this->blockManifest as $block) {
			if($block->isWith()) {
				$ret[] = $block;
			}
		}

		return $ret;
	}
	
	/**
	 * Gets all the variables at the top level, mapped as $VariableName => $FieldType
	 * @return array
	 */
	public function getTopLevelVars() {
		return $this->topBlock->getVars();
	}

	/**
	 * Gets all the blocks at the top level
	 * @return array
	 */
	public function getTopLevelBlocks() {
		$ret = array ();
		foreach($this->blockManifest as $block) {
			if(!$block->getParent()) {
				$ret[] = $block;			
			}
		}

		return $ret;
	}

	/**
	 * Gets all the possible boolean variables at the top level, e.g. <% if $Foo %>
	 * @return array
	 */
	public function getTopLevelBooleans() {
		return $this->topBlock->getPossibleBooleans();
	}
	
	/**
	 * Gets the code being analysed
	 * @return string
	 */
	public function getCode() {
		return $this->code;
	}
}


/**
 * A class representing a block inside a {@link ReflectionTemplate}. Can
 * intelligently get its parent, child blocks, variables, etc.
 * 
 * @author  Uncle Cheese <unclecheese@leftandmain.com>
 * @package  silverstripe-reflection-templates
 */
class ReflectionTemplate_Block extends Object {
	
	/**
	 * A reference to the parent {@link ReflectionTemplate}
	 * @var ReflectionTemplate
	 */
	protected $reflector;
	
	/**
	 * The entire contents of the parent template
	 * @var string
	 */
	protected $allContents;

	/**
	 * The "outer" contents of the block, including the <% .. %> <% end_.. %> delimiters
	 * @var string
	 */
	protected $blockOuterContents;
	
	/**
	 * The "inner" contents of the block, excluding the delimiters
	 * @var string
	 */
	protected $blockInnerContents;

	/**
	 * The opening syntax for this block, e.g. <% loop $Items.limit(5) %>
	 * @var string
	 */
	protected $openingDelimiter;
	
	/**
	 * A list of the nested blocks
	 * @var array
	 */
	protected $children = array ();
	
	/**
	 * A list of the variables in this block
	 * @var array
	 */
	protected $vars;
	
	/**
	 * A list of the possible booleans in this block, e.g. <% if .. %>q
	 * @var array
	 */
	protected $possibleBooleans;
	
	/**
	 * The name of this block, e.g. <% loop $Items %> is named "Items"
	 * @var string
	 */
	protected $name;
	
	/**
	 * The ID of this block, or its offset in the parent template string
	 * @var int
	 */
	protected $id;

	/**
	 * The type of block: "loop", "with", or "root" 
	 * @var string
	 */
	protected $type;
	
	/**
	 * Constructor. Processes the block and generates the inner/outer contents. Computes
	 * the name and the type of block
	 * @param ReflectionTemplate $reflector   
	 * @param integer            $start       The start position of this block in the parent template
	 * @param [type]             $end         The end position of this block in the parent template
	 * @param integer            $parentIndex The start position of the parent block
	 */
	public function __construct(ReflectionTemplate $reflector, $start = 0, $end = null, $parentIndex = 0) {
		parent::__construct();
		
		$this->reflector = $reflector;
		$this->id = $start;
		
		if(!$end) {
			$end = strlen($this->reflector->getCode());
		}
		
		$this->allContents = $reflector->getCode();
		$this->blockOuterContents = substr($this->allContents, $this->id, ($end-$this->id)+14); // strlen of <% end_loop|with %>
		$this->parentIndex = $parentIndex;
		
		preg_match("/<% (loop|with) [\$]?([A-Za-z0-9_]+)(.*?) %>/", $this->blockOuterContents, $match);
		if($match) {
			$this->openingDelimiter = $match[0];
			$this->name = trim($match[2]);
			$this->type = $match[1];
			$this->blockInnerContents = substr($this->allContents, ($this->id+strlen($this->openingDelimiter)), $end-$this->id);			

		}
		// If the start position is 0, and the parent is 0, this must be the root.
		else if($start == 0 && $parentIndex == 0) {
			$this->name = "Root";
			$this->type = "root";
			$this->blockInnerContents = substr($this->allContents, $this->id, $end-$this->id);	
		}
		else {
			throw new Exception("ReflectionTemplate_Block given a code block that is not properly formed: {$this->blockOuterContents}");
		}
	}
	
	/**
	 * Adds a child block to this block
	 * @param int $index The offset of the child block
	 */
	public function addChild($index) {
		foreach($this->children as $child) {
			if($child->getID() == $index) return;
		}
		$this->children[] = $this->reflector->getBlockByID($index);
	}
	
	/**
	 * Gets the parent block
	 * @return ReflectionTemplate_Block
	 */
	public function getParent() {
		return $this->reflector->getBlockByID($this->parentIndex);
	}
	
	/**
	 * Gets all the vars in this block, mapped by $VarName => $Type. 
	 * Highly opinionated. Based on the convention that all template methods
	 * are UpperCamelCase.
	 * @return array
	 */
	public function getVars() {
		$dbFieldFunctions = $this->reflector->getDBFieldFunctions();

		if($this->vars != null) return $this->vars;
		
		$vars = array ();
		$counts = array ();
		$booleans = $this->getPossibleBooleans();
		$search = $this->getTopLevelContent();
		
		preg_match_all("/\\$[A-Za-z0-9._]+/", $search, $variables);		
		
		if($variables || $booleans) {
			foreach(reset($variables) as $m) {
				$label = str_replace("$","", $m);

				// If using the dot syntax, this may be a has_one, or an invocation of a DBField method.
				if(stristr($label, ".") !== false) {
					list($relation, $name) = explode('.', $label);
					$name = preg_replace('/\(.*\)/','',$name);
					// The variable is a core template accessor. Move on.
					if(in_array(strtolower($relation), $this->reflector->getTemplateAccessors())) {					
						continue;
					}
					// The method being called against the variable is a DBField function.
					// Use that information to assign a probable FieldType					
					$methodName = strtolower($name);
					if(array_key_exists($methodName, $dbFieldFunctions)) {
						$class = $dbFieldFunctions[$methodName];
						$vars[$relation] = $dbFieldFunctions[$methodName];
					}
					// The variable name is the same as a ViewableData class. Chances are
					// this is a has_one to another class, e.g. $has_one = array ('File' => 'File');
					elseif(is_subclass_of($relation, 'ViewableData')) {
						$vars[$relation] = $relation;
					}
					// This variable is using a dot syntax, and neither the variable nor the
					// method are known. It must be a user-defined has_one.
					else {
						$vars[$relation] = "has_one";
					}
				}
				else {
					if(!isset($counts[$label])) $counts[$label] = 0;
					$counts[$label]++;
	
					if(!in_array($label, $vars) && !$this->getChildByName($label)) {
						// This is a <% with %> block, and it's not using a common template accessor,
						// e.g. $Up, so we can make a guess about the datatype of this variable.
						if(!$this->isLoop() && !in_array(strtolower($label), $this->reflector->getTemplateAccessors())) {
							$vars[$label] = $this->reflector->inferDatatype($label);
						}
						// This is a loop, and the variable is not something like $First, $Last, or $Pos.
						else if($this->isLoop() && !in_array(strtolower($label), $this->reflector->getListFunctions())) {
							$vars[$label] = $this->reflector->inferDatatype($label);
						}
					}					
				}
			}
		}

		foreach($booleans as $b) {
			if(isset($counts[$b])) {
				$counts[$b]--;
				if($counts[$b] == 0) {
					$vars[$b] = 'Boolean';
				}
			}
		}

		return $this->vars = $vars;
	}

	/**
	 * Gets all the content at the $Top level. Removes blocks.
	 * @return string
	 */
	public function getTopLevelContent() {
		$content = $this->blockInnerContents;
		$offset = strlen($this->openingDelimiter);

		foreach($this->getChildren() as $c) {
			$length = strlen($c->getOuterContents());
			$content = substr_replace($content, str_repeat("\n", $length), $c->getRelativeOffset()+$offset, $length);
		}

		return str_replace("\n", "", $content);
	}

	/**
	 * Adds a new variable to this block
	 * @param string $name  The variable name
	 * @param string $class The FieldType class
	 */
	public function addVar($name, $class) {
		$this->vars[$name] = $class;
	}
	
	/**
	 * Gets a list of the possible booleans in this template, e.g. <% if $SoldOut %>
	 * @return array
	 */
	public function getPossibleBooleans() {
		if($this->possibleBooleans) return $this->possibleBooleans;
		
		$booleans = array ();
		preg_match_all("/<% (if not|if) [\$]?([A-Za-z0-9._]+)(.*?) %>/", $this->getTopLevelContent(), $matches);
		
		if($matches) {
			foreach($matches[2] as $m) {
				$label = trim($m);
				// We already identified this boolean earlier in the loop
				if(in_array($label, $booleans)) continue;

				// The condition is based on a core template accessor, e.g. <% if $Menu(2) %>
				if(in_array(strtolower($label), $this->reflector->getTemplateAccessors())) continue;

				// The condition is based on a core list method, e.g. <% if $Last %>
				if(in_array(strtolower($label), $this->reflector->getListFunctions())) continue;

				// The condition is based on a block we have already identified, e.g. <% if $Items %>
				if($this->reflector->getBlockByName($label)) continue;

				$booleans[] = $label;
			}
		}
		
		return $this->possibleBooleans = $booleans;
	}
	
	/**
	 * Get the name of this block
	 * @return string
	 */
	public function getName() {
		return $this->name;
	}
	
	/**
	 * Gets the direct descendants of this block
	 * @return array
	 */
	public function getChildren() {
		return $this->children;
	}

	/**
	 * Gets a child block by name
	 * @param  string $name 
	 * @return ReflectionTemplate_Block
	 */
	public function getChildByName($name) {
		foreach($this->getChildren() as $child) {
			if($child->getName() == $name) return $child;
		}

		return false;
	}
	
	/**
	 * Gets the outer contents of the block. Includes the block delimiters
	 * @return string
	 */
	public function getOuterContents() {
		return $this->blockOuterContents;
	}

	/**
	 * Gets the outer contents of the block. Excludes the block delimiters
	 * @return string
	 */
	public function getInnerContents() {
		return $this->blockOuterContents;
	}
	
	/**
	 * Gets the identifier, or offset, of this block
	 * @return int
	 */
	public function getID() {
		return $this->id;
	}

	/**
	 * Gets the offset, relative to the parent block
	 * @return int
	 */
	public function getRelativeOffset() {
		if($this->getParent()) {
			return $this->id - $this->getParent()->getID();
		}

		return $this->id;
	}

	/**
	 * Returns true if this is the $Top block
	 * @return boolean 
	 */
	public function isRoot() {
		return $this->type == 'root';
	}

	/**
	 * Returns true if this block is a <% loop %>
	 * @return boolean
	 */
	public function isLoop() {
		return $this->type == 'loop';
	}

	/**
	 * Returns true if this is a <% with %> block
	 * @return boolean [description]
	 */
	public function isWith() {
		return $this->type == 'with';
	}
}
