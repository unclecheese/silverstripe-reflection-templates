<?php

/**
 * A {@link ReflectionTemplate} class designed for working with SiteTree
 * templates. Populates the commmon template accessors with known SiteTree/ContentController
 * methods.
 *
 * @author Uncle Cheese <unclecheese@leftandmain.com>
 * @package  silverstripe-reflection-templates
 */
class SiteTreeReflectionTemplate extends ReflectionTemplate {

	/**
	 * Gets all the core template accessors available to SiteTree templates
	 * and caches the result
	 * @return array
	 */
	public function getTemplateAccessors() {
		if($this->templateAccessors) return $this->templateAccessors;
		
		$vars = parent::getTemplateAccessors();


		$cc = new ReflectionClass('ContentController');
		$site_tree = new ReflectionClass('SiteTree');
		$hierarchy = new ReflectionClass('Hierarchy');
		
		$methods = array_merge(
			$site_tree->getMethods(), 
			$cc->getMethods(),
			$hierarchy->getMethods(),
			array_keys(singleton('SiteTree')->has_many()),
			array_keys(singleton('SiteTree')->many_many()),
			array_keys(singleton('SiteTree')->db()),
			array_keys(DataObject::config()->fixed_fields)
		);		
		
		foreach($methods as $m) {
			$name = is_object($m) ? $m->getName() : $m;
			// We only care about methods that follow the UpperCamelCase convention.
			if(preg_match("/[A-Z]/",$name[0])) {
				$vars[] = $name;
			}
		}
		// Just a random exception case
		$vars[] = "Form";

		return $this->templateAccessors = $vars;
	}

}