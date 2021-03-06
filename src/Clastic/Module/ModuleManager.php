<?php
/*
 * This file is part of the Clastic package.
 *
 * (c) Dries De Peuter <dries@nousefreak.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Clastic\Module;

use Clastic\Clastic;
use Symfony\Component\Config\ConfigCache;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Yaml\Yaml;

/**
 * This manager handles everything that involves more than one moduleController.
 */
class ModuleManager
{
    /**
     * Holds the routeCollection for all modules.
     *
     * @var \Symfony\Component\Routing\RouteCollection
     */
    protected static $routes;

    /**
     * Get the routeCollection from the cache.
     * If the cache is non existing, it will try all modules.
     *
     * @return \Symfony\Component\Routing\RouteCollection
     */
    public static function createModuleRoutes()
    {
      if (!is_null(static::$routes)) {
        return static::$routes;
      }
      $cachePath = CLASTIC_ROOT . '/cache/routes-' . Clastic::getSiteId() . '.php';
      $routesCache = new ConfigCache($cachePath, Clastic::$debug);
      if (!$routesCache->isFresh()) {
        static::$routes = new RouteCollection();
        $finder = new Finder();
        $iterator = $finder
          ->directories()
          ->depth(0)
          ->in(static::getModulePaths());
        $tmpRoutes = array();
        foreach ($iterator as $module) {
          $tmpRoutes[$module->getRelativePathname()]['path'] = $module->getRealPath();
          if (file_exists($module->getRealPath() . '/Resources/config/routes.yml')) {
            $configRoutes = Yaml::parse($module->getRealPath() . '/Resources/config/routes.yml');
            foreach ((array)$configRoutes as $name => $route) {
              $tmpRoutes[$module->getRelativePathname()]['routes'][$name] = $route;
            }
          }
        }
        static::$routes = new RouteCollection();
        foreach ($tmpRoutes as $module => $routes) {
          $routeCollection = new RouteCollection();
          if (isset($routes['routes'])) {
            foreach ($routes['routes'] as $name => $route) {
              $params = $route;
              $controller = str_replace(array(CLASTIC_ROOT . '/app', '/'), array('', '\\'), $routes['path']) . '\\Controller\\' . $module . 'Controller';
              $params['_controller'] = $controller . '::handle';
              unset($params['_pattern']);
              $routeCollection->add($name, new Route($route['_pattern'], $params));
            }
            static::$routes->addCollection($routeCollection);
          }
        }
        $routesCache->write(serialize(static::$routes));
      }
      else {
        static::$routes = unserialize(Yaml::parse($cachePath));
      }
      return static::$routes;
    }

    /**
     * Collect all database metadata from all modules and store them in one folder.
     *
     * @todo implement this
     *
     * @param $path string
     */
    public static function collectDatabaseEntities($path)
    {
        if (is_dir($path) || mkdir($path, 0777, true)) {
            file_put_contents($path . '/.htaccess', 'deny from all');
            $finder = new Finder();
            $iterator = $finder->directories()
                ->name('Entities')->in(static::getModulePaths());
            foreach ($iterator as $dir) {
                $fileFinder = new Finder();
                $files = $fileFinder->files()
                    ->in($dir->getPath())
                    ->name('*.php');
                foreach ($files as $entitie) {
                    copy($entitie->getRealPath(), $path . '/' . $entitie->getFilename());
                }
            }
        }
    }

    /**
     * Get all directories where modules are stored.
     *
     * @return string[]
     */
    public static function getModulePaths()
    {
        return array_filter(
            Clastic::getPaths('/Modules'),
            function ($directory) {
                return is_dir($directory);
            }
        );
    }

    /**
     * Get all directories of all modules.
     *
     * @return string[]
     */
    public static function getModuleDirectories($subDirectory = null)
    {
        $directories = array();
        foreach (static::getModulePaths() as $path) {
            $modules = new Finder();
            $modules->directories()
                ->in($path)
                ->depth(0);
            foreach ($modules as $module) {
                if (is_null($subDirectory)) {
                    $directories[$module->getRelativePathname()] = $module->getPathname();
                } elseif(is_dir($module->getPathname() . '/' . $subDirectory)) {
                    $directories[$module->getRelativePathname()] = $module->getPathname() . '/' . $subDirectory;
                }
            }
        }
        return $directories;
    }

    public static function getModuleNamespaces($subDirectory = null)
    {
        $entityNamespaces = array();
        foreach (ModuleManager::getModuleDirectories($subDirectory) as $module => $directory) {
            $entityNamespaces[$module] = str_replace(array(CLASTIC_ROOT . '/app/', '/'), array('', '\\'), $directory);
        }
        return $entityNamespaces;
    }
}