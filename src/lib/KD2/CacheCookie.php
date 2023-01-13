<?php
/*
    This file is part of KD2FW -- <http://dev.kd2.org/>

    Copyright (c) 2001-2019 BohwaZ <http://bohwaz.net/>
    All rights reserved.

    KD2FW is free software: you can redistribute it and/or modify
    it under the terms of the GNU Affero General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    Foobar is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU Affero General Public License for more details.

    You should have received a copy of the GNU Affero General Public License
    along with Foobar.  If not, see <https://www.gnu.org/licenses/>.
*/

namespace KD2;

/**
 * Cache Cookie
 * (C) 2011-2014 BohwaZ
 * Inspired by Frank Denis (C) 2011 Public domain
 * https://00f.net/2011/01/19/thoughts-on-php-sessions/
 */

class CacheCookie
{
    /**
     * Name of the cookie
     * @var string
     */
    protected $name = 'cache';

    /**
     * Secret key/random hash
     * @var string
     */
    protected $secret_key = null;

    /**
     * Digest method for hash_hmac
     * @var string
     */
    protected $digest_method = 'sha256';

    /**
     * Delay before expiration when we should renew the cookie
     * before it expires (in minutes)
     * @var integer
     */
    protected $auto_renew = 30;

    /**
     * Default cookie path
     * @var string
     */
    protected $path = '/';

    /**
     * Default cookie domain
     * @var string
     */
    protected $domain = null;

    /**
     * Default cookie duration, in minutes
     * Will also determine data validity
     * @var integer
     */
    protected $duration = 0;

    /**
     * True if the cookie should only be sent over a SSL/TLS connection
     * @var boolean
     */
    protected $secure = false;

    /**
     * Start timestamp used to store a shorter timestamp in the cookie
     * @var integer
     */
    protected $start_timestamp = 1391209200; //2014-02-01 00:00:00

    /**
     * Cookie content
     * @var array
     */
    protected $content = null;

    /**
     * Cookie HTTP only parameter
     * @var boolean
     */
    protected $httponly = false;

    /**
     * Create a new CacheCookie instance and setup default parameters
     * @param string $name     Cookie name
     * @param string $secret   Secret random hash (should stay the same for at least the cookie duration)
     * @param int    $duration Cookie duration, in minutes, set to 0 (zero) to make the cookie lasts for the
     * whole user agent session (cookie will be deleted when the browser is closed).
     * @param string $path     Cookie path
     * @param string $domain   Cookie domain, if left null the current HTTP_HOST or SERVER_NAME will be used
     * @param string $secure   Set to TRUE if the cookie should only be sent on a secure connection
     */
    public function __construct($name = null, $secret = null, $duration = null, $path = null, $domain = null, $secure = false, $httponly = false)
    {
        if (!is_null($name))
        {
            $this->name = $name;
        }

        if (!is_null($secret))
        {
            $this->secret = $secret;
        }
        else
        {
            // Default secret key
            $this->secret = \hash('sha256', (isset($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : ''));
        }

        if (!is_null($duration))
        {
            $this->duration = (int) $duration;
        }

        if (!is_null($path))
        {
            $this->path = $path;
        }

        if (!is_null($domain))
        {
            $this->domain = $domain;
        }

        $this->secure = (bool)$secure;
        $this->httponly = (bool)$httponly;
    }

    public function setAutoRenew($renew)
    {
        $this->auto_renew = (int) $renew;
    }

    /**
     * Gets the cookie content
     * @return array Data contained in the cookie
     */
    protected function _getCookie()
    {
        if (!is_null($this->content))
        {
            return $this->content;
        }

        $cookie = null;
        $this->content = new \stdClass;

        if (!empty($_COOKIE[$this->name]))
        {
            $cookie = $_COOKIE[$this->name];
        }

        if (!empty($cookie) && (substr_count($cookie, '|') >= 2))
        {
            list($digest, $expire, $data) = explode('|', $cookie, 3);

            // Check data expiration and integrity
            if (!empty($digest) && !empty($data) && !empty($expire) 
                && ($expire > round((time() - $this->start_timestamp) / 60))
                && hash_equals($digest, hash_hmac($this->digest_method, $expire . '|' . $data, $this->secret)))
            {
                if (substr($data, 0, 1) == '{')
                {
                    $this->content = (object) json_decode($data, true);
                }
                elseif (function_exists('msgpack_unpack'))
                {
                    $this->content = (object) msgpack_unpack($data);
                }

                // If the cookie will expire soon we try to renew it first
                if ($this->auto_renew && ($expire - round((time() - $this->start_timestamp)/60) <= $this->auto_renew))
                {
                    $this->save();
                }
            }
            else
            {
                // Invalid cookie: just remove it
                $this->save();
            }
        }

        return $this->content;
    }

    /**
     * Sends the cookie content to the user-agent
     * @return boolean TRUE for success, 
     * or RuntimeException if the HTTP headers have already been sent
     */
    public function save()
    {
        if (headers_sent())
        {
            throw new \RuntimeException('Cache cookie can not be saved as headers have '
                . 'already been sent to the user agent.');
        }

        $headers = headers_list(); // List all headers
        header_remove(); // remove all headers
        $regexp = '/^Set-Cookie\\s*:\\s*' . preg_quote($this->name) . '=/';

        foreach ($headers as $header)
        {
            // Re-add every header except the one for this cookie
            if (!preg_match($regexp, $header))
            {
                header($header, true);
            }
        }

        if (!empty($this->content) && count($this->content) > 0)
        {
            if (function_exists('msgpack_pack'))
            {
                $data = msgpack_pack($this->content);
            }
            else
            {
                $data = json_encode($this->content);
            }

            // Store expiration time in minutes
            $data = round((time() - $this->start_timestamp + $this->duration*60)/60) . '|' . $data;

            $cookie = hash_hmac($this->digest_method, $data, $this->secret) . '|' . $data;

            $duration = $this->duration ? time() + $this->duration * 60 : 0;

            if (strlen($cookie . $this->path . $duration . $this->domain . $this->name) > 4080)
            {
                throw new \OverflowException('Cache cookie can not be saved as its size exceeds 4KB.');
            }

            setcookie($this->name, $cookie, $duration, $this->path, $this->domain, $this->secure, true);
            $_COOKIE[$this->name] = $cookie;
        }
        else
        {
            setcookie($this->name, '', 1, $this->path, $this->domain, $this->secure, true);
            unset($_COOKIE[$this->name]);
        }

        return true;
    }

    /**
     * Set a key/value pair in the cache cookie
     * @param mixed  $key   Key (integer or string)
     * @param mixed  $value Value (integer, string, boolean, array, float...)
     */
    public function set($key, $value)
    {
        $this->_getCookie();

        if (is_null($value))
        {
            unset($this->content->$key);
        }
        else
        {
            $this->content->$key = $value;
        }

        return true;
    }

    /**
     * Get data from the cache cookie, if $key is NULL then all the keys will be returned
     * @param  mixed    $key Data key
     * @return mixed    NULL if the key is not found, or content of the requested key
     */
    public function get($key = null)
    {
        $content = $this->_getCookie();

        if (is_null($key))
        {
            return $content;
        }

        if (!isset($content->$key))
        {
            return null;
        }
        else
        {
            return $content->$key;
        }
    }

    /**
     * Delete the cookie and all its data
     * @return boolean TRUE
     */
    public function delete()
    {
        $content = $this->get();

        foreach ($content as $key=>$value)
        {
            $this->set($key, null);
        }

        return $this->save();
    }

    /**
     * Returns raw cookie data
     * @return string cookie content
     */
    public function getRawData()
    {
        if (isset($_COOKIE[$this->name]))
            return $_COOKIE[$this->name];

        return null;
    }
}
