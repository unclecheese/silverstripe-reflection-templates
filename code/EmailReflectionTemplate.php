<?php

/**
 * A {@link ReflectionTemplate} class designed for working with Email
 * templates. Provides all the template variables that are made available to all emails
 * generated using the core Email class.
 *
 * @author Uncle Cheese <unclecheese@leftandmain.com>
 * @package  silverstripe-reflection-templates
 */
class EmailReflectionTemplate extends ReflectionTemplate {

	/**
	 * Gets all the template accessors and caches the result
	 * @return array
	 */
	public function getTemplateAccessors() {
		if($this->templateAccessors) return $this->templateAccessors;

		$vars = parent::getTemplateAccessors();

		return $this->templateAccessors = array_merge($vars, array (
			'To',
			'Cc',
			'Bcc',
			'From',
			'Subject',
			'Body',
			'BaseURL',
			'IsEmail'
		));
	}
}