<?php
namespace Plugin\Stackbox;
use Alloy;

/**
 * Stackbox Plugin
 * Enables main CMS hooks and ensures classes are autoloaded
 */
class Plugin
{
    protected $kernel;


    /**
     * Initialize plguin
     */
    public function __construct(Alloy\Kernel $kernel)
    {
        $this->kernel = $kernel;

        // Add Stackbox and Nijikodo classes to the load path
        $loader = $kernel->loader();
        $loader->registerNamespace('Stackbox', __DIR__ . '/lib');
        $loader->registerNamespace('Nijikodo', __DIR__ . '/lib');

        // Get current config settings
        $cfg = $kernel->config();
        $app = $cfg['app'];

        // Hostname lookup
        $hostname = $kernel->request()->server('HTTP_HOST');

        // Get site by hostname
        try {
            $siteMapper = $kernel->mapper('Module\Site\Mapper');
            $site = $siteMapper->getSiteByDomain($hostname);
        } catch(\Exception $e) {
            $content = $kernel->dispatch('page', 'install');
            echo $content;
            exit();
        }

        // Site not found - setup first site automaticlly on first viewed hostname
        if(!$site) {
            // Count sites
            $siteCount = $siteMapper->all('Module\Site\Entity')->count();
            if(0 == $siteCount) {
                // Add first site with current hostname
                $newSite = $siteMapper->create('Module\Site\Entity', array(
                    'reseller_id' => 0,
                    'shortname' => $hostname,
                    'title' => 'StackboxCMS Website',
                    'theme' => 'default',
                    'status' => \Module\Site\Entity::STATUS_ACTIVE,
                    'date'
                ));
                $siteSaved = $siteMapper->save($newSite);

                // Add site domain record
                if($siteSaved) {
                    $siteDomain = $siteMapper->create('Module\Site\Domain', array(
                        'site_id' => $newSite->id,
                        'domain' => $hostname,
                        'type' => \Module\Site\Domain::TYPE_NORMAL
                    ));
                    $siteMapper->save($siteDomain);

                    // Set site
                    $site = $newSite;
                }
            }
        }

        // Site not found - no hostname match
        if(!$site) {
            throw new \Stackbox\Exception\SiteNotFound("Site <b>" . $hostname . "</b> not found.");
        }

        // Make site object available on Kernel
        $kernel->addMethod('site', function() use($site) {
            return $site; 
        });

        // Set site files directory based on id
        $siteFilesDir = 'site/' . $site->shortname . '/';

        // Add config settings
        $kernel->config(array(
            'cms' => array(
                'site' => array(
                    'id' => $site->id,
                    'title' => $site->title
                ),
                'dir' => array(
                    'modules' => 'content/',
                    'themes' => 'themes/',
                    'files' => $siteFilesDir,
                    'assets_admin' => $cfg['app']['dir']['assets'] . 'admin/'
                ),

                'default' => array(
                    'module' => 'page',
                    'action' => 'index',
                    'theme' => 'default',
                    'theme_template' => 'index'
                )
            )
        ));

        // Get config again because we need to use settings we just added
        $cfg = $kernel->config();
        $kernel->config(array(
            'cms' => array(
                'path' => array(
                    'modules' => $app['path']['root'] . $app['dir']['www'] . 'content/',
                    'themes' => $app['path']['root'] . $app['dir']['www'] . 'themes/',
                    'files' => $app['path']['root'] . $app['dir']['www'] . $siteFilesDir
                ),
                'url' => array(
                    'assets_admin' => $cfg['url']['root'] . str_replace($app['dir']['www'], '', $cfg['cms']['dir']['assets_admin']),
                    'themes' => $cfg['url']['root'] . str_replace($app['dir']['www'], '', $cfg['cms']['dir']['themes']),
                    'files' => $cfg['url']['root'] . str_replace($app['dir']['www'], '', $cfg['cms']['dir']['files'])
                )
            )
        ));

        // This adds to the load path because it already exists (does not replace it)
        $kernel->loader()->registerNamespace('Module', $site->moduleDirs());

        // Layout / API output
        $kernel->events()->addFilter('dispatch_content', 'cms_layout_api_output', array($this, 'layoutOrApiOutput'));

        // Add 'autoinstall' method as callback for cms 'module_dispatch_exception' filter when exceptions are encountered
        $kernel->events('cms')->addFilter('module_dispatch_exception', 'stackbox_autoinstall_on_exception', array($this, 'autoinstallOnException'));

        // If debugging, track execution time and memory usage
        if($kernel->config('app.mode.development') || $kernel->config('app.debug')) {
            $timeStart = microtime(true);
            $kernel->events()->bind('boot_stop', 'cms_bench_time', function() use($timeStart) {
                $timeEnd = microtime(true);
                $timeDiff = $timeEnd - $timeStart;
                echo "\n<!-- Stackbox Execution Time: " . number_format($timeDiff, 6) . "s -->";
                echo "\n<!-- Stackbox Memory Usage:   " . number_format(memory_get_peak_usage(true) / (1024*1024), 2) . " MB -->\n\n";
            });
        }

        // Add sub-plugins and other plugins Stackbox depends on
        $kernel->plugin('Stackbox_User');
        $kernel->plugin('Module\Filebrowser');
    }


    /**
     * Ensure layout or API type output is served correctly
     */
    public function layoutOrApiOutput($content)
    {
        $kernel = $this->kernel;
        $request = $kernel->request();
        $response = $kernel->response();

        $response->contentType('text/html');

        // Default cache settings for frontend/proxy caches like nginx and Varnish
        $response->header("Expires", gmdate("D, d M Y H:i:s", strtotime('+2 hours')) . " GMT");
        $response->header("Last-Modified", gmdate( "D, d M Y H:i:s" ) . " GMT");
        $response->header("Cache-Control", "max-age=7200, must-revalidate");
        $response->header("Pragma", "public");

        $layoutName = null;
        if($content instanceof Alloy\View\Template) {
            $layoutName = $content->layout();
        }

        // Only if layout is explicitly given
        if($layoutName) {
            $layout = new \Alloy\View\Template($layoutName, $request->format);
            $layout->path($kernel->config('app.path.layouts'))
                ->format($request->format);

            // Ensure layout exists
            if (false === $layout->exists()) {
                return $content;
            }

            // Pass along set response status and data if we can
            if($content instanceof Alloy\Module\Response) {
                $layout->status($content->status());
                $layout->errors($content->errors());
            }

            // Pass set title up to layout to override at template level
            if($content instanceof Alloy\View\Template) {
                // Force render layout so we can pull out variables set in template
                $contentRendered = $content->content();
                $layout->head()->title($content->head()->title());
                $content = $contentRendered;
            }

            $layout->set(array(
                'kernel'  => $kernel,
                'content' => $content
            ));

            return $layout;
        }

        // Send correct response
        if(in_array($request->format, array('json', 'xml'))) {
            $response = $kernel->response();

            // No cache and hide potential errors
            ini_set('display_errors', 0);
            $response->header("Expires", "Mon, 26 Jul 1997 05:00:00 GMT"); 
            $response->header("Last-Modified", gmdate( "D, d M Y H:i:s" ) . "GMT"); 
            $response->header("Cache-Control", "no-cache, must-revalidate"); 
            $response->header("Pragma", "no-cache");
            
            // Correct content-type
            if('json' == $request->format) {
                $response->contentType('application/json');
            } elseif('xml' == $request->format) {
                $response->contentType('text/xml');
            }
        }

        return $content;
    }


    /**
     * Autoinstall missing tables on exception
     */
    public function autoinstallOnException($content)
    {
        $kernel = \Kernel();

        // Database error
        if($content instanceof \PDOException
          || $content instanceof \Spot\Exception_Datasource_Missing) {
            if($content instanceof \Spot\Exception_Datasource_Missing
              ||'42S02' == $content->getCode()
              || false !== stripos($content->getMessage(), 'Base table or view not found')) {
                // Last dispatch attempt
                $ld = $kernel->lastDispatch();

                // Debug trace message
                $mName = is_object($ld['module']) ? get_class($ld['module']) : $ld['module'];
                $kernel->trace("PDO Exception on module '" . $mName . "' when dispatching '" . $ld['action'] . "' Attempting auto-install in Stackbox plugin at " . __METHOD__ . "", $content);

                // Table not found - auto-install module to cause Entity migrations
                $content = $kernel->dispatch($ld['module'], 'install');

                if(false !== $content) {
                    $content = "<strong>[[ Auto-installed module '" . $mName . "'. Please refresh page ]]</strong>";
                }
            }
        }

        return $content;
    }
}