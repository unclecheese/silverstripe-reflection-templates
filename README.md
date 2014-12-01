# Reflection Templates for SilverStripe
A set of classes that introspect SilverStripe templates, getting metadata about variables and blocks, much like PHP's ReflectionClass.

## Installation
`composer require unclecheese/reflection-templates:dev-master`

## Requirements
SilverStripe 3.1 or higher

## Usage

Given a template such as this:
```html
<div>
  <h2>$Headline</h2>
  <div>$Image.CroppedImage(200,200)</div>
  <h3>$Category.Title</h3>
  <ul>
  <% loop $Items %>
    <li>$Title ($Date.Nice)</li>
    <ul>
      <% loop $Artcles %>
      <li>This article, called $ArticleTitle is related to $Up.Title</li>
      <% end_loop %>
  <% end_loop %>
  
  <% with $FeaturedProduct %>
    <h3>$Description</h3>
  <% end_with %>
</div>
```
We can introspect it using a `ReflectionTemplate` like so:

```php
 $reflector = ReflectionTemplate::create();
 $reflector->process(file_get_contents('/path/to/template.ss'));
  
foreach($reflector->getTopLevelVars() as $varName => $type) {
	echo "The template variable $varName is likely a $type\n";
}

foreach($reflector->getTopLevelBlocks() as $block) {
	echo "There is a block at the top level named {$block->getName()}\n";
	echo $block->isLoop() ? "\tThis block is a loop\n" : "\tThis block is a with\n";
	foreach($block->getVars() as $var => $type) {
		echo "\tThe top level block {$block->getName()} contains a variable named $var that is likely a $type\n";
	}
	foreach($block->getChildren() as $child) {
		echo "\tThere is a child block named {$child->getName()}. It has the following vars:\n";
		foreach($child->getVars() as $v => $t) {
			echo "\t\tThe nested block {$child->getName()} contains a variable named $v that is likely a $t\n";
		}
	}
}	 	
```
This produces the following result:

```
The template variable Headline is likely a Text
The template variable Image is likely a Image
The template variable Category is likely a has_one
There is a block at the top level named Items
	This block is a loop
	The top level block Items contains a variable named Title that is likely a Text
	The top level block Items contains a variable named Date that is likely a Date
	There is a child block named Artcles. It has the following vars:
		The nested block Artcles contains a variable named ArticleTitle that is likely a Text
There is a block at the top level named FeaturedProduct
	This block is a with
	The top level block FeaturedProduct contains a variable named Description that is likely a Text
```

## Contextual template reflection

You can use one of the two context-sensitive reflectors to surface only variables and blocks that are user-defined.

* `SiteTreeReflectionTemplate` comes loaded with context about methods and variables that are made available to all `SiteTree` and `ContentController` contexts, and filters out things like `$Menu`, `$SiteConfig`, etc.
* `EmailReflectionTemplate` works similarly, filtering out variables like `To`, `Subject`, etc., that are made available to all emails.
 
## For all that is good and holy in this world, WHY?!

I found myself in need of it recently, and I had all this code kicking around from the old [SilverSmith](http://github.com/unclecheese/SilverSmith) project, and decided its best not left to sit and rot into oblivion. Hopefully someone else can make use of this insanity.

## Todo

Add a task that will generate PHP classes given a template

