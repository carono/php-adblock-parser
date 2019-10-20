<?php

namespace Limonte;

use Symfony\Component\Cache\Adapter\FilesystemAdapter;

/**
 * Class AdblockParser
 *
 * @package Limonte
 * @property AdblockRule[] $rules
 */
class AdblockParser
{
    private $rules;
    private $cache;

    public $cacheClass = FilesystemAdapter::class;

    private $cacheFolder;

    private $cacheExpire = 1; // 1 day

    public function __construct($rules = [])
    {
        $this->rules = [];
        $this->addRules($rules);
    }

    /**
     * @param $request
     * @param $data
     */
    public function setCacheValue($request, $data)
    {
        $item = $this->getCache()->getItem(md5($request));
        $item->set($data);
        $this->getCache()->save($item);
    }

    /**
     * @return \Symfony\Component\Cache\Adapter\AbstractAdapter
     */
    public function getCache()
    {
        if ($this->cache) {
            return $this->cache;
        }
        $cache = new $this->cacheClass('parser', 60 * 24 * $this->getCacheExpire(), $this->getCacheFolder());
        $this->cache = $cache;
        return $cache;
    }

    /**
     * @param $request
     * @return mixed
     */
    public function getCacheValue($request)
    {
        return $this->getCache()->getItem(md5($request))->get();
    }

    public function clearCache()
    {
        $this->getCache()->clear();
//        FileHelper::removeDirectory(static::getTemporaryDir());
    }

    /**
     * @param  string[] $rules
     * @param bool $useCache
     */
    public function addRules($rules, $useCache = true)
    {
        $md5 = md5(serialize($rules));
        if ($useCache && ($ruleObjects = $this->getCacheValue($md5))) {
            file_put_contents('1.txt', print_r($ruleObjects, 1));
            $this->rules = $ruleObjects;
            return;
        }

        foreach ($rules as $rule) {
            try {
                $this->rules[] = new AdblockRule($rule);
            } catch (InvalidRuleException $e) {
                // Skip invalid rules
            }
        }

        // Sort rules, exceptions first
        usort($this->rules, function (AdblockRule $a, AdblockRule $b) {
            return (int)$a->isException() < (int)$b->isException();
        });

        $this->setCacheValue($md5, $this->rules);
    }

    /**
     * @param  string|array $path
     * @param bool $useCache
     */
    public function loadRules($path, $useCache = true)
    {
        // single resource
        if (is_string($path)) {
            if (filter_var($path, FILTER_VALIDATE_URL)) {
                $content = $this->getCacheValue($path);
                if (!$content) {
                    $this->setCacheValue($path, $content = @file_get_contents($path));
                }
            } else {
                $content = @file_get_contents($path);
            }

            if ($content) {
                $rules = preg_split("/(\r\n|\n|\r)/", $content);
                $this->addRules($rules, $useCache);
            }
            // array of resources
        } elseif (is_array($path)) {
            foreach ($path as $item) {
                $this->loadRules($item, $useCache);
            }
        }
    }

    /**
     * @return  array
     */
    public function getRules()
    {
        return $this->rules;
    }

    /**
     * @param  string $url
     *
     * @return boolean
     * @throws \Exception
     */
    public function shouldBlock($url)
    {
        $url = trim($url);

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new \Exception('Invalid URL');
        }

        foreach ($this->rules as $rule) {

            if ($rule->isComment() || $rule->isHtml()) {
                continue;
            }

            if ($rule->matchUrl($url)) {
                if ($rule->isException()) {
                    return false;
                }
                return true;
            }
        }

        return false;
    }

    /**
     * @param  string $url
     *
     * @return boolean
     */
    public function shouldNotBlock($url)
    {
        return !$this->shouldBlock($url);
    }

    /**
     * Get cache folder
     *
     * @return string
     */
    public function getCacheFolder()
    {
        return $this->cacheFolder ?: dirname(__DIR__) . '/cache';
    }

    /**
     * Set cache folder
     *
     * @param  string $cacheFolder
     */
    public function setCacheFolder($cacheFolder)
    {
        $this->cacheFolder = rtrim($cacheFolder, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }

    /**
     * Get cache expire (in days)
     *
     * @return integer
     */
    public function getCacheExpire()
    {
        return $this->cacheExpire;
    }

    /**
     * Set cache expire (in days)
     *
     * @param  integer $expireInDays
     */
    public function setCacheExpire($expireInDays)
    {
        $this->cacheExpire = $expireInDays;
    }

    /**
     * Clear external resources cache
     */
//    public function clearCache()
//    {
//        if ($this->cacheFolder) {
//            foreach (glob($this->cacheFolder . '*') as $file) {
//                unlink($file);
//            }
//        }
//    }

    /**
     * @param  string $url
     *
     * @return string
     */
    private function getCachedResource($url)
    {
        if (!$this->cacheFolder) {
            return @file_get_contents($url);
        }

        $cacheFile = $this->cacheFolder . basename($url) . md5($url);

        if (file_exists($cacheFile) && (filemtime($cacheFile) > (time() - 60 * 24 * $this->cacheExpire))) {
            // Cache file is less than five minutes old.
            // Don't bother refreshing, just use the file as-is.
            $content = @file_get_contents($cacheFile);
        } else {
            // Our cache is out-of-date, so load the data from our remote server,
            // and also save it over our cache for next time.
            $content = @file_get_contents($url);
            if ($content) {
                file_put_contents($cacheFile, $content, LOCK_EX);
            }
        }

        return $content;
    }
}
