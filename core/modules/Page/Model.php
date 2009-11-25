<?php
/**
 * $Id$
 */
class Module_Page_Model extends phpDataMapper_Model
{
	// Custom row class
	protected $rowClass = 'Module_Page_Item';
	
	// Setup table and fields
	protected $table = "pages";
	protected $fields = array(
		'id' => array('type' => 'int', 'primary' => true),
		'title' => array('type' => 'string'),
		'url' => array('type' => 'string', 'key' => true, 'required' => true),
		'meta_keywords' => array('type' => 'string'),
		'meta_description' => array('type' => 'string'),
		'template' => array('type' => 'string'),
		'date_created' => array('type' => 'date'),
		'date_modified' => array('type' => 'date')
		);
	
	/**
	 * Get current page by given URL
	 */
	public function getPageByUrl($url)
	{
		return $this->first(array(
			'url' => $url
			));
	}
}


// Custom row object
class Module_Page_Item extends phpDataMapper_Model_Row
{
	
}