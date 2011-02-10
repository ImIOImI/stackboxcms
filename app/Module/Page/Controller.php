<?php
namespace Module\Page;
use Stackbox;

/**
 * Page controller - sets up whole page for display
 */
class Controller extends Stackbox\Module\ControllerAbstract
{
    /**
     * @method GET
     */
    public function indexAction($request)
    {
        return $this->viewUrl($request->page);
    }
    
    
    /**
     * View page by URL
     */
    public function viewUrl($pageUrl)
    {
        $kernel = $this->kernel;
        $request = $kernel->request();
        $user = $kernel->user();
        
        // Ensure page exists
        $mapper = $kernel->mapper();
        $pageMapper = $kernel->mapper('Module\Page\Mapper');
        $pageUrl = Entity::formatPageUrl($pageUrl);
        $page = $pageMapper->getPageByUrl($pageUrl);
        if(!$page) {
            if($pageUrl == '/') {
                // Create new page for the homepage automatically if it does not exist
                $page = $pageMapper->get('Module\Page\Entity');
                $page->site_id = $kernel->config('site.id');
                $page->parent_id = 0;
                $page->title = "Home";
                $page->url = $pageUrl;
                $page->date_created = $pageMapper->connection('Module\Page\Entity')->dateTime();
                $page->date_modified = $page->date_created;
                if(!$pageMapper->save($page)) {
                    throw new \Alloy\Exception_FileNotFound("Unable to automatically create homepage at '" . $pageUrl . "' - Please check data source permissions");
                }
            } else {
                throw new \Alloy\Exception_FileNotFound("Page not found: '" . $pageUrl . "'");
            }
        }
        
        // Single module call?
        // @todo Check against matched route name instead of general request params (? - may restict query string params from being used)
        if($request->module_name && $request->module_action) {
            $moduleId = (int) $request->module_id;
            $moduleName = $request->module_name;
            $moduleAction = $request->module_action;
            
            if($moduleId == 0) {
                // Get new module entity, no ID supplied
                // @todo Possibly restrict callable action with ID of '0' to 'new', etc. because other functions may depend on saved and valid module record
                $module = $mapper->get('Module\Page\Module\Entity');
                $module->name = $request->name;
            } else {
                // Module belongs to current page
                $module = $page->modules->where(array('id' => $moduleId))->first();
            }
            
            // Setup dummy module object if there is none loaded
            if(!$module) {
                $module = $mapper->get('Module\Page\Module\Entity');
                $module->name = $request->name;
            }
            
            // Load requested module
            $moduleObject = $kernel->module($moduleName);
            
            // Ensure user can execute requested action
            if(!$moduleObject->userCanExecute($user, $moduleAction)) {
                throw new Alloy_Exception_Auth("User does not have sufficient permissions to execute requested action (" . $moduleAction . "). Please login and try again.");
            }
            
            // Dispatch to single module
            $moduleResponse = $kernel->dispatchRequest($moduleObject, $moduleAction, array($page, $module));
            
            // Return content immediately, currently not wrapped in template
            return $this->regionModuleFormat($request, $page, $module, $user, $moduleResponse);
        }
        
        // Load page template
        $activeTheme = ($page->theme) ? $page->theme : $kernel->config('stackbox.default.theme');
        $activeTemplate = ($page->template) ? $page->template : $kernel->config('stackbox.default.theme_template');
        $themeUrl = $kernel->config('stackbox.url.themes') . $activeTheme . '/';
        $template = new Template($activeTemplate);
        $template->format($request->format);
        $template->path($kernel->config('stackbox.path.themes') . $activeTheme . '/');
        $template->parse();
        
        // Template Region Defaults
        $regionModules = array();
        foreach($template->regions() as $regionName => $regionData) {
            $regionModules[$regionName] = $regionData['content'];
        }
        
        // Modules
        $modules = $page->modules;
        
        // Also include modules in global template regions if global regions are present
        if($template->regionsType('global')) {
            $modules->orWhere(array('site_id' => $kernel->config('site.id'), 'region' => $template->regionsType('global')));
        }
        foreach($modules as $module) {
            // Loop over modules, building content for each region
            $moduleResponse = $kernel->dispatch($module->name, 'indexAction', array($request, $page, $module));
            if(!is_array($regionModules[$module->region])) {
                $regionModules[$module->region] = array();
            }
            $regionModules[$module->region][] = $this->regionModuleFormat($request, $page, $module, $user, $moduleResponse);
        }
        
        // Replace region content
        $regionModules = $kernel->events('stackbox')->filter('module_page_template_regions', $regionModules);
        foreach($regionModules as $region => $modules) {
            if(is_array($modules)) {
                // Array = Region has modules
                $regionContent = implode("\n", $modules);
            } else {
                // Use default content between tags in template (no other content)
                $regionContent = (string) $modules;
            }
            $template->replaceRegion($region, $this->regionFormat($request, $region, $regionContent));
        }
        
        // Replace template tags
        $tags = $mapper->data($page);
        $tags = $kernel->events('stackbox')->filter('module_page_template_data', $tags);
        foreach($tags as $tagName => $tagValue) {
            $template->replaceTag($tagName, $tagValue);
        }
        
        // Template string content
        $template->clean(); // Remove all unmatched tokens
        $templateHead = $template->head();
        
        // Admin stuff for HTML format
        if($template->format() == 'html') {
            // Add user and admin stuff to the page
            if($user && $user->isAdmin()) {
                // Admin toolbar, javascript, styles, etc.
                $templateHead->script('http://ajax.googleapis.com/ajax/libs/jquery/1.4.2/jquery.min.js');
                $templateHead->script('http://ajax.googleapis.com/ajax/libs/jqueryui/1.8.5/jquery-ui.min.js');
                
                // Setup javascript variables for use
                $templateHead->prepend('<script type="text/javascript">var cx = {page: {id: ' . $page->id . ', url: "' . $pageUrl . '"}, config: {url: "' . $kernel->config('url.root') . '", url_assets: "' . $kernel->config('url.assets') . '", url_assets_admin: "' . $kernel->config('stackbox.url.assets_admin') . '"}};</script>' . "\n");
                $templateHead->script($kernel->config('stackbox.url.assets_admin') . 'scripts/ckeditor/ckeditor.js');
                $templateHead->script($kernel->config('stackbox.url.assets_admin') . 'scripts/ckeditor/adapters/jquery.js');
                $templateHead->script($kernel->config('stackbox.url.assets_admin') . 'scripts/cx_admin.js');
                $templateHead->stylesheet('jquery-ui/aristo/aristo.css');
                $templateHead->stylesheet($kernel->config('stackbox.url.assets_admin') . 'styles/cx_admin.css');
                
                // Grab template contents
                $template = $template->content();
                
                // Admin bar and edit controls
                $templateBodyContent = $this->template('_adminBar')
                    ->path(__DIR__ . '/views/')
                    ->set('page', $page);
                $template = str_replace("</body>", $templateBodyContent . "\n</body>", $template);
            }
            
            // Global styles and scripts
            $templateHead->stylesheet($kernel->config('url.assets') . 'styles/cx_modules.css');
            
            // Render head
            $template = str_replace("</head>", $templateHead->content() . "\n</head>", $template);
            
            // Prepend asset path to beginning of "href" or "src" attributes that is prefixed with '@'
            $template = preg_replace("/<(.*?)([src|href]+)=\"@([^\"|:]+)\"([^>]*)>/i", "<$1$2=\"".$themeUrl."$3\"$4>", $template);
            // Replace '!' prepend with web URL root
            $template = preg_replace("/<(.*?)([src|href]+)=\"!([^\"|:]*)\"([^>]*)>/i", "<$1$2=\"".$this->kernel->config('url.root')."$3\"$4>", $template);
        } else {
            // Other output formats not supported at this time
            return false; // 404
        }
        
        return $template;
    }
    
    
    /**
     * @method GET
     */
    public function newAction($request)
    {
        return $this->formView()
            ->method('post')
            ->action($this->kernel->url(array('page' => '/'), 'page'));
    }
    
    
    /**
     * @method GET
     */
    public function editAction($request)
    {
        $kernel = $this->kernel;
        
        // Ensure page exists
        $mapper = $this->kernel->mapper('Module\Page\Mapper');
        $page = $mapper->getPageByUrl($request->page);
        if(!$page) {
            throw new \Alloy\Exception_FileNotFound("Page not found: '" . $request->page . "'");
        }
        
        return $this->formView()->data($page->data());
    }
    
    
    /**
     * Create a new resource with the given parameters
     * @method POST
     */
    public function postMethod($request)
    {
        $mapper = $this->kernel->mapper('Module\Page\Mapper');
        $entity = $mapper->get('Module\Page\Entity')->data($request->post());
        $entity->site_id = $this->kernel->config('site.id');
        $entity->parent_id = (int) $request->parent_id;
        $entity->date_created = $mapper->connection('Module\Page\Entity')->dateTime();
        $entity->date_modified = $entity->date_created;
        
        // Auto-genereate URL if not filled in
        if(!$request->url) {
            $entity->url = $this->kernel->formatUrl($request->title);
        }
        if($mapper->save($entity)) {
            $pageUrl = $this->kernel->url(array('page' => $entity->url), 'page');
            if($request->format == 'html') {
                return $this->kernel->redirect($pageUrl);
            } else {
                return $this->kernel->resource($entity)->status(201)->location($pageUrl);
            }
        } else {
            $this->kernel->response(400);
            return $this->newAction($request)
                ->data($request->post())
                ->errors($mapper->errors());
        }
    }
    
    
    /**
     * @method GET
     */
    public function deleteAction($request)
    {
        if($request->format == 'html') {
            $view = new \Alloy\View\Generic\Form('form');
            $form = $view
                ->method('delete')
                ->action($this->kernel->url(array('page' => $request->page), 'page'))
                ->submitButtonText('Delete');
            return "<p>Are you sure you want to delete this page and ALL data and modules on it?</p>" . $form;
        }
        return false;
    }
    
    
    /**
     * @method DELETE
     */
    public function deleteMethod($request)
    {
        // Ensure page exists
        $mapper = $this->kernel->mapper('Module\Page\Mapper');
        $page = $mapper->getPageByUrl($request->page);
        if(!$page) {
            return false;
        }
        
        $mapper->delete($page);
    }
    
    
    /**
     * @method GET
     */
    public function sitemapAction($request)
    {
        $kernel = $this->kernel;
        
        // Ensure page exists
        $mapper = $kernel->mapper('Module\Page\Mapper');
        $page = $mapper->getPageByUrl($request->url);
        if(!$page) {
            return false;
        }
        
        $pages = $mapper->pageTree();
        
        // View template
        return $this->view(__FUNCTION__)
            ->format($request->format)
            ->set(array('pages' => $pages));
    }
    
    
    /**
     * Return view object for the add/edit form
     */
    protected function formView()
    {
        $view = parent::formView();
        $fields = $view->fields();
        
        // Override int 'parent_id' with option select box
        $fields['parent_id']['type'] = 'select';
        // Get all pages for site
        $fields['parent_id']['options'] = array(0 => '[None]') + $this->kernel->mapper('Module\Page\Mapper')->all('Module\Page\Entity', array(
                'site_id' => $this->kernel->config('site.id')
            ))->order(array(
                'ordering' => 'ASC'
            ))->toArray('id', 'title'); // Return records in 'id' => 'title' key/value array
        $fields['parent_id']['title'] = 'Parent Page';
        
        // Prepare view
        $view->action("")
            ->fields($fields)
            ->removeFields(array('id', 'theme', 'template', 'ordering', 'date_created', 'date_modified'));
        return $view;
    }
    
    
    
    /**
     * Format region return content for display on page response
     */
    protected function regionFormat($request, $regionName, $regionContent)
    {
        if('html' == $request->format) {
            $content = '<div id="cx_region_' . $regionName . '" class="cx_region">' . $regionContent . '</div>';
        }
        return $content;
    }
    
    
    /**
     * Format module return content for display on page response
     */
    protected function regionModuleFormat($request, $page, $module, $user, $moduleResponse, $includeControls = true)
    {
        $content = "";
        if(false === $moduleResponse) {
            $content = false;
        } else {
            if('html' == $request->format) {
                // Module placeholder
                if(true === $moduleResponse || empty($moduleResponse)) {
                    $moduleResponse = "<p>&lt;" . $module->name . " Placeholder&gt;</p>";
                }
                $content = '
                <div id="cx_module_' . $module->id . '" class="cx_module module_' . strtolower($module->name) . '">
                  ' . $moduleResponse;
                // Show controls only for authorized users and requests that are not AJAX
                if($includeControls && false !== $user && $user->isAdmin()) {
                    $content .= '
                  <div class="cx_ui cx_ui_controls">
                    <div class="cx_ui_title"><span>' . $module->name . '</span></div>
                    <ul>
                      <li><a href="' . $this->kernel->url(array('page' => $page->url, 'module_name' => ($module->name) ? $module->name : $this->name(), 'module_id' => (int) $module->id, 'module_action' => 'edit'), 'module') . '">Edit</a></li>
                      <li><a href="' . $this->kernel->url(array('page' => $page->url, 'module_name' => 'page_module', 'module_id' => 0, 'module_item' => (int) $module->id, 'module_action' => 'delete'), 'module_item') . '">Delete</a></li>
                    </ul>
                  </div>
                  ';
                }
                $content .= '</div>';
            }
        }
        return $content;
    }
    
    
    /**
     * Install Module
     *
     * @see Cx_Module_Controller_Abstract
     */
    public function install($action = null, array $params = array())
    {
        $this->kernel->mapper('Module\Page\Mapper')->migrate('Module\Page\Entity');
        $this->kernel->mapper('Module\Page\Mapper')->migrate('Module\Page\Module\Entity');
        return parent::install($action, $params);
    }
    
    
    /**
     * Uninstall Module
     *
     * @see Cx_Module_Controller_Abstract
     */
    public function uninstall()
    {
        return $this->kernel->mapper('Module\Page\Mapper')->dropDatasource('Module\Page\Entity');
    }
}