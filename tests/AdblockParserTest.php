<?php

namespace Limonte\Tests;

use Limonte\AdblockParser;

/**
 * Class AdblockParserTest
 *
 * @package Limonte\Tests
 * @property AdblockParser $parser
 */
class AdblockParserTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @expectedException Exception
     */
    public function testInvalidUrl()
    {
        $this->parser = new AdblockParser;
        $this->shouldBlock('sfsaf');
    }

    public function testBlockByAddressParts()
    {
        $this->parser = new AdblockParser(['/banner/*/img^']);
        $this->shouldBlock([
            'http://example.com/banner/foo/img',
            'http://example.com/banner/foo/bar/img?param',
            'http://example.com/banner//img/foo',
        ]);
        $this->shouldNotBlock([
            'http://example.com/banner/img',
            'http://example.com/banner/foo/imgraph',
            'http://example.com/banner/foo/img.gif',
        ]);
    }

    public function testBlockByDomainName()
    {
        $this->parser = new AdblockParser(['||ads.example.com^']);
        $this->shouldBlock([
            'http://ads.example.com/foo.gif',
            'http://server1.ads.example.com/foo.gif',
            'https://ads.example.com:8000/',
        ]);
        $this->shouldNotBlock([
            'http://ads.example.com.ua/foo.gif',
            'http://example.com/redirect/http://ads.example.com/',
        ]);

        $this->parser = new AdblockParser(['|http://baddomain.example/']);
        $this->shouldBlock([
            'http://baddomain.example/banner.gif',
        ]);
        $this->shouldNotBlock([
            'http://gooddomain.example/analyze?http://baddomain.example',
        ]);
    }

    public function testBlockExactAddress()
    {
        $this->parser = new AdblockParser(['|http://example.com/|']);
        $this->shouldBlock([
            'http://example.com/',
        ]);
        $this->shouldNotBlock([
            'http://example.com/foo.gif',
            'http://example.info/redirect/http://example.com/',
        ]);
    }

    public function testBlockBeginningDomain()
    {
        $this->parser = new AdblockParser(['||example.com/banner.gif']);
        $this->shouldBlock([
            'http://example.com/banner.gif',
            'https://example.com/banner.gif',
            'http://www.example.com/banner.gif',
        ]);
        $this->shouldNotBlock([
            'http://badexample.com/banner.gif',
            'http://gooddomain.example/analyze?http://example.com/banner.gif',
        ]);
    }

    public function testCaretSeparator()
    {
        $this->parser = new AdblockParser(['http://example.com^']);
        $this->shouldBlock([
            'http://example.com/',
            'http://example.com:8000/ ',
        ]);
        $this->shouldNotBlock([
            'http://example.com.ar/',
        ]);

        $this->parser = new AdblockParser(['^example.com^']);
        $this->shouldBlock([
            'http://example.com:8000/foo.bar?a=12&b=%D1%82%D0%B5%D1%81%D1%82',
        ]);

        $this->parser = new AdblockParser(['^%D1%82%D0%B5%D1%81%D1%82^']);
        $this->shouldBlock([
            'http://example.com:8000/foo.bar?a=12&b=%D1%82%D0%B5%D1%81%D1%82',
        ]);

        $this->parser = new AdblockParser(['^foo.bar^']);
        $this->shouldBlock([
            'http://example.com:8000/foo.bar?a=12&b=%D1%82%D0%B5%D1%81%D1%82',
        ]);
    }

    public function testParserException()
    {
        $this->parser = new AdblockParser(['adv', '@@advice.']);
        $this->shouldBlock([
            'http://example.com/advert.html',
        ]);
        $this->shouldNotBlock([
            'http://example.com/advice.html',
        ]);

        $this->parser = new AdblockParser(['@@|http://example.com', '@@advice.', 'adv', '!foo']);
        $this->shouldBlock([
            'http://examples.com/advert.html',
        ]);
        $this->shouldNotBlock([
            'http://example.com/advice.html',
            'http://example.com/advert.html',
            'http://examples.com/advice.html',
            'http://examples.com/#!foo',
        ]);
    }

    public function testLoadRulesLocally()
    {
        $this->parser = new AdblockParser;
        $this->parser->loadRules(__DIR__ . '/test-rules.txt');
        $this->assertEquals(4, count($this->parser->getRules()));
        $this->shouldBlock([
            'http://example.com/avantlink/123',
            'http://example.com//avmws_asd.js',
        ]);
        $this->shouldNotBlock('http://example.com//avmws_exception.js');
    }

    public function testLoadRemoteRules()
    {
        $this->parser = new AdblockParser;
        $this->assertEquals(1, $this->parser->getCacheExpire());
        $this->parser->setCacheFolder(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'cache');
        $this->parser->clearCache();
        $glob = $this->parser->getCacheFolder() . '*';
        $this->assertEquals(0, count(glob($glob)));
        $this->parser->loadRules([
            'https://raw.githubusercontent.com/easylist/easylist/master/easylistfanboy/other/adult-addon.txt',
            'https://raw.githubusercontent.com/easylist/easylist/master/easylistfanboy/other/tracking-intl.txt',
        ]);
        $this->assertEquals(2, count(glob($glob)));
        $this->parser->loadRules([
            'https://raw.githubusercontent.com/easylist/easylist/master/easylistfanboy/other/adult-addon.txt',
        ]);

        $this->parser->clearCache();
        $this->assertEquals(0, count(glob($glob)));

        $this->parser->setCacheExpire(0);

        $this->shouldBlock('http://dot.wp.pl/');
    }

    public function testLoadArrayOfResources()
    {
        $this->parser = new AdblockParser;
        $this->parser->loadRules([
            'https://raw.githubusercontent.com/easylist/easylist/master/easylistfanboy/other/adult-addon.txt',
            'https://raw.githubusercontent.com/easylist/easylist/master/easylistfanboy/other/tracking-intl.txt',
        ]);

        $this->shouldBlock('http://rek.www.wp.pl');    // rule from the first resource
        $this->shouldBlock('http://webcount.finn.no'); // rule from the second resource
        $this->shouldNotBlock('');
    }

    /**
     * @dataProvider easyListUrlProvider
     */
    public function testEasyList($url, $isShould)
    {
        $this->parser = new AdblockParser;
        $this->parser->loadRules([__DIR__ . '/easylist.txt'], false);
        if ($isShould) {
            $this->shouldBlock($url);
        } else {
            $this->shouldNotBlock($url);
        }
    }

    private function shouldBlock($url)
    {
        if (is_string($url)) {
            $this->assertTrue($this->parser->shouldBlock($url), $url);
        } elseif (is_array($url)) {
            foreach ($url as $i) {
                $this->assertTrue($this->parser->shouldBlock($i), $i);
            }
        }
    }

    private function shouldNotBlock($url)
    {
        if (is_string($url)) {
            $this->assertTrue($this->parser->shouldNotBlock($url), $url);
        } elseif (is_array($url)) {
            foreach ($url as $i) {
                $this->assertTrue($this->parser->shouldNotBlock($i), $i);
            }
        }
    }

    public function easyListUrlProvider()
    {
        return [
            ['http://clickscloud.net/', 1],
            ['http://apygame.com/click', 1],
            ['http://apollocdn.cc', 1],
            ['http://gamexp.ru', 0]
        ];
    }
}
