<?php
namespace Module\Page;
use Stackbox;
use Spot;

class Entity extends Stackbox\EntityAbstract
{
    const VISIBILITY_HIDDEN = 0;
    const VISIBILITY_VISIBLE = 1;


    // Table
    protected static $_datasource = "pages";

    // Public property that will contain child pages when Mapper::pageTree() is called
    public $children = array();
    
    // Public property that will contain path to this page when Mapper::pageTree() is called
    public $id_path = '';
    public $is_in_path = false;
    
    /**
     * Fields
     */
    public static function fields() {
        return array(
            'id' => array('type' => 'int', 'primary' => true, 'serial' => true),
            'site_id' => array('type' => 'int', 'default' => 0, 'unique' => 'site_page'),
            'parent_id' => array('type' => 'int', 'index' => true, 'default' => 0),
            'title' => array('type' => 'string', 'required' => true),
            'url' => array('type' => 'string', 'required' => true, 'unique' => 'site_page'),
            'meta_keywords' => array('type' => 'string'),
            'meta_description' => array('type' => 'string'),
            'template' => array('type' => 'string'),
            'ordering' => array('type' => 'int', 'length' => 4, 'default' => 0),
            'visibility' => array('type' => 'int', 'length' => 1, 'default' => self::VISIBILITY_VISIBLE),
            'date_created' => array('type' => 'datetime'),
            'date_modified' => array('type' => 'datetime')
        ) + parent::fields();
    }
    
    /**
     * Relations
     */
    public static function relations() {
        return array(
            /*
            // Subpages / hierarchy
            'children' => array(
                'type' => 'HasMany',
                'entity' => ':self',
                'where' => array('site_id' => ':entity.site_id', 'parent_id' => ':entity.id'),
                'order' => array('ordering' => 'ASC')
                ),
            */
            // Modules in regions on page
            'modules' => array(
                'type' => 'HasMany',
                'entity' => 'Module\Page\Module\Entity',
                'where' => array('site_id' => ':entity.site_id', 'page_id' => ':entity.id'),
                'order' => array('ordering' => 'ASC')
                )
        ) + parent::relations();
    }
    
    
    /**
     * Formats URL on save
     */
    public function beforeSave(Spot\Mapper $mapper)
    {
        $this->__set('site_id', \Kernel()->config('cms.site.id'));
        $this->__set('url', self::formatPageUrl($this->__get('url')));
        return parent::beforeSave($mapper);
    }
    
    
    /**
     * Format a page URL by ensuring there is a begining and ending slash
     *
     * @param string $url
     * @return string
     */
    public static function formatPageUrl($url)
    {
        if(empty($url)) {
            $url = '/';
        } elseif($url != '/') {
            $url = '/' . trim($url, '/') . '/';
        }
        return $url;
    }


    /**
     * Get templates available to page
     */
    public static function getPageTemplates()
    {
        $kernel = \Kernel();
        $site = $kernel->site();

        // Build array of theme directories to look in
        $tplDir = $kernel->config('cms.path.themes');
        $tplDirs = array();
        foreach($site->themes() as $theme) {
            $tplDirs[] = rtrim($tplDir, '/') . '/' . $theme . '/';
        }

        // Find template files
        $templates = $kernel->finder()
            ->in($tplDirs)
            ->files()
            ->name('*.html.tpl')
            ->depth(0)
            ->sortByName();
        
        $tpls = array();
        foreach($templates as $tpl) {
            // Remove path info
            $tplRelPath = str_replace($tplDir, '', $tpl->getPathname());
            // Remove extensions
            $tplRelPath = str_replace('.html.tpl', '', $tplRelPath);
            // Set in array to use
            $tpls[$tplRelPath] = $tplRelPath;
        }

        return $tpls;
    }


    /**
     * Is page visible?
     * 
     * @return boolean
     */
    public function isHomepage()
    {
        return ('/' == $this->url);
    }


    /**
     * Is page visible?
     * 
     * @return boolean
     */
    public function isVisible()
    {
        return ($this->visibility == self::VISIBILITY_VISIBLE);
    }
}