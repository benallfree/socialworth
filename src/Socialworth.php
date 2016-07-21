<?php

namespace BenAllfree;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ParseException;
use Respect\Validation\Validator;

class Socialworth
{
    protected $client = null;

    protected $url = null;

    public $services = [
        'twitter'     => true,
        'facebook'    => true,
        'pinterest'   => true,
        'reddit'      => true,
        'stumbleupon' => true,
        'linkedin'    => true,
        'hackernews'  => false,
        'testcase'    => false,
    ];

    /**
     * Constructor
     * @param String $url      
     * @param array  $services restrict counter to specific services; ex: ['twitter', 'linkedin']
     */
    public function __construct($url = null, array $services = [])
    {
        $this->url($url);

        if (!empty($services)) {
            $this->services = array_fill_keys(array_keys($this->services), false);

            foreach ($services as $service) {
                $this->services[$service] = true;
            }
        }
    }

    public function url($url)
    {
        if ($url) {
            if (Validator::sf('Url')->validate($url)) {
                $this->url = $url;
                
            } else {
                throw new \InvalidArgumentException(_('The address provided is not a valid URL.'));
            }

        } else {
            return $this->url;
        }

        return $this;
    }

    public function all()
    {
        $endpoints = $this->apiEndpoints();
        $response = [ 'total' => 0 ];

        foreach ($this->services as $service => $enabled) {
            if ($enabled && isset($endpoints[$service])) {
                $actions = $this->__get($endpoints[$service]);
                $response[$service] = $actions;
                $response['total'] += $actions;
            }
        }

        return (Object) $response;
    }

    public function setClient(Client $client)
    {
        $this->client = $client;
    }

    public function __set($service, $enabled)
    {
        if (isset($this->services[$service])) {
            $this->services[$service] = (bool) $enabled;
            return true;
        }

        return false;
    }

    public function __get($service)
    {
        if (is_string($service)) {
            $service   = strtolower($service);
            $endpoints = $this->apiEndpoints();

            if (isset($endpoints[$service])) {
                $service = $endpoints[$service];

            } else {
                throw new \Exception(sprintf(_('Unknown service %s'), $service));
            }
        }

        if (!is_array($service) || !isset($service['url'])) {
            throw new \InvalidArgumentException(_('Argument expected to be a service name as a string.'));
        }

        return $service['callback']($this->apiRequest($service['url']));

    }

    public function __isset($service)
    {
        if (isset($this->services[$service])) {
            return (bool) $this->services[$service];
        }

        return false;
    }

    public function __unset($service)
    {
        if (isset($this->services[$service])) {
            $this->services[$service] = false;
            
            return true;
        }

        return false;
    }

    public function __call($service, $arguments = [])
    {
        $previous_url = $this->url;

        if (isset($arguments[0]) && filter_var($arguments[0], FILTER_VALIDATE_URL)) {
            $this->url = $arguments[0];
        }

        $response = $this->__get($service);
        $this->url = $previous_url;
        return $response;
    }

    public static function __callStatic($service, $arguments = [])
    {
        if (!isset($arguments[0]) || !filter_var($arguments[0], FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException(_('You must specify an address to query.'));
        }

        $instance = new Socialworth($arguments[0]);
        return $instance->$service;
    }

    protected function apiRequest($endpoint)
    {
        if ( !isset($this->url)) {
            throw new \InvalidArgumentException(_('You must specify an address to query.'));
        }

        if ( !$this->client) {
            $this->setClient(new Client());
        }

        $response = $this->client->get(rtrim($endpoint, '?&'));

        try {
            if ($response) {
                $raw    = $response->getBody()->getContents();
                $isJson = strpos($response->getHeader('Content-Type'), 'json') !== false;

                return ($raw and $isJson) ? $response->json() : $raw;
            }

        } catch (ParseException $e) {
            return $raw;
        }

        return false;
    }

    protected function apiEndpoints()
    {
        return [
            'testcase' => [
                'url'      => 'http://thisisbogus.supercalifragilisticexpialidocious.io',
                'callback' => function($resp) {
                    return $resp;
                }
            ],

            'facebook' => [
                'url'      => 'https://graph.facebook.com/fql?q=' . urlencode("SELECT like_count, total_count, share_count, click_count, comment_count FROM link_stat WHERE url = \"{$this->url}\""),
                'callback' => function($resp) {
                    $resp = json_decode($resp);

                    if ($resp && isset($resp->data[0]->total_count) && $resp->data[0]->total_count) {
                        return (int)$resp->data[0]->total_count;
                    }

                    return 0;
                }
            ],

            'pinterest' => [
                'url'      => 'http://api.pinterest.com/v1/urls/count.json?url='.$this->url,
                'callback' => function($resp) {
                    if ($resp) {
                        $resp = json_decode(substr($resp, strpos($resp, '{'), -1));
                        
                        if (isset($resp->count) && $resp->count) {
                            return (int) $resp->count;
                        }
                    }

                    return 0;
                }
            ],

            'twitter' => [
                'url'      => 'http://opensharecount.com/count.json?url='.$this->url,
                'callback' => function($resp) {
                    return (isset($resp['count']) && $resp['count'])
                        ? (int) $resp['count']
                        : 0;
                }
            ],

            'linkedin' => [
                'url'      => 'http://www.linkedin.com/countserv/count/share?format=json&url='.$this->url,
                'callback' => function($resp) {
                    return (isset($resp['count']) && $resp['count'])
                        ? (int) $resp['count']
                        : 0;
                }
            ],

            'stumbleupon' => [
                'url'      => 'http://www.stumbleupon.com/services/1.01/badge.getinfo?url='.$this->url,
                'callback' => function($resp) {
                    $resp = json_decode($resp);

                    return (isset($resp->result->views) && $resp->result->views)
                        ? (int) $resp->result->views
                        : 0;
                }
            ],

            'reddit' => [
                'url'      => 'http://www.reddit.com/api/info.json?url='.$this->url,
                'callback' => function($resp) {
            
                    if (isset($resp['data']['children']) && $resp['data']['children']) {
                        $c = 0;

                        foreach ($resp['data']['children'] as $story) {
                            if (isset($story['data']['ups'])) {
                                $c = $c + (int) $story['data']['ups'];
                            }
                        }

                        return (int) $c;
                    }

                    return 0;
                }
            ],

            'hackernews' => [
                'url'      => 'http://api.thriftdb.com/api.hnsearch.com/items/_search?q=&filter[fields][url]='.$this->url,
                'callback' => function($resp) {
                    $resp = json_decode($resp);

                    if ($resp && isset($resp->results) && $resp->results) {
                        $c = 0;
                        foreach ($resp->results as $story) {
                            $c++;
                            if (isset($story->item) && isset($story->item->points)) {
                                $c = $c + (int)$story->item->points;
                            }
                            if (isset($story->item) && isset($story->item->num_comments)) {
                                $c = $c + (int)$story->item->num_comments;
                            }
                        }
                        return (int)$c;
                    }

                    return 0;
                }
            ],
        ];
    }
}
