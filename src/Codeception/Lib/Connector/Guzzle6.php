<?php
namespace Codeception\Lib\Connector;

use Codeception\Exception\ConnectionException;
use Codeception\Util\Uri;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response as Psr7Response;
use GuzzleHttp\Psr7\Uri as Psr7Uri;
use Symfony\Component\BrowserKit\Client;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\BrowserKit\Request as BrowserKitRequest;
use Symfony\Component\BrowserKit\Request;
use Symfony\Component\BrowserKit\Response as BrowserKitResponse;

class Guzzle6 extends Client
{
    protected $requestOptions = [
        'allow_redirects' => false,
        'headers'         => [],
    ];
    protected $refreshMaxInterval = 0;

    /** @var \GuzzleHttp\Client */
    protected $client;

    /**
     * Sets the maximum allowable timeout interval for a meta tag refresh to
     * automatically redirect a request.
     *
     * A meta tag detected with an interval equal to or greater than $seconds
     * would not result in a redirect.  A meta tag without a specified interval
     * or one with a value less than $seconds would result in the client
     * automatically redirecting to the specified URL
     *
     * @param int $seconds Number of seconds
     */
    public function setRefreshMaxInterval($seconds)
    {
        $this->refreshMaxInterval = $seconds;
    }

    public function setClient(GuzzleClient &$client)
    {
        $this->client = $client;
    }

    /**
     * Sets the request header to the passed value.  The header will be
     * sent along with the next request.
     *
     * Passing an empty value clears the header, which is the equivelant
     * of calling deleteHeader.
     *
     * @param string $name the name of the header
     * @param string $value the value of the header
     */
    public function setHeader($name, $value)
    {
        if (strval($value) === '') {
            $this->deleteHeader($name);
        } else {
            $this->requestOptions['headers'][$name] = $value;
        }
    }

    /**
     * Deletes the header with the passed name from the list of headers
     * that will be sent with the request.
     *
     * @param string $name the name of the header to delete.
     */
    public function deleteHeader($name)
    {
        unset($this->requestOptions['headers'][$name]);
    }

    public function setAuth($username, $password)
    {
        $this->requestOptions['auth'] = [$username, $password];
    }

    /**
     * Taken from Mink\BrowserKitDriver
     *
     * @param Response $response
     *
     * @return \Symfony\Component\BrowserKit\Response
     */
    protected function createResponse(Psr7Response $response)
    {
        $body = (string) $response->getBody();
        $headers = $this->flattenHeaders($response->getHeaders());

        $contentType = null;
        if (isset($headers['Content-Type'])) {
            $contentType = $headers['Content-Type'];
        }
        if (!$contentType) {
            $contentType = 'text/html';
        }
        if (strpos($contentType, 'charset=') === false) {
            if (preg_match('/\<meta[^\>]+charset *= *["\']?([a-zA-Z\-0-9]+)/i', $body, $matches)) {
                $contentType .= ';charset=' . $matches[1];
            }
            $headers['Content-Type'] = $contentType;
        }

        $status = $response->getStatusCode();
        $matches = [];

        $matchesMeta = preg_match(
            '/\<meta[^\>]+http-equiv="refresh" content="(\d*)\s*;?\s*url=(.*?)"/i',
            $body,
            $matches
        );

        if (!$matchesMeta && isset($headers['Refresh'])) {
            // match by header
            preg_match(
                '~(\d*);?url=(.*)~',
                (string) $headers['Refresh'],
                $matches
            );
        }

        if ((!empty($matches)) && (empty($matches[1]) || $matches[1] < $this->refreshMaxInterval)) {
            $uri = new Psr7Uri($this->getAbsoluteUri($matches[2]));
            $currentUri = new Psr7Uri($this->getHistory()->current()->getUri());

            if ($uri->withFragment('') != $currentUri->withFragment('')) {
                $status = 302;
                $headers['Location'] = (string) $uri;
            }
        }

        return new BrowserKitResponse($body, $status, $headers);
    }

    protected function flattenHeaders($headers)
    {
        return array_map(function ($header) {
            return reset($header);
        }, $headers);

    }

    public function getAbsoluteUri($uri)
    {
        $baseUri = $this->client->getConfig('base_uri');
        if (strpos($uri, '://') === false) {
            if (strpos($uri, '/') === 0) {
                return Uri::appendPath((string)$baseUri, $uri);
            }
            // relative url
            if (!$this->getHistory()->isEmpty()) {
                return Uri::mergeUrls((string)$this->getHistory()->current()->getUri(), $uri);
            }
        }
        return Uri::mergeUrls($baseUri, $uri);
    }

    protected function doRequest($request)
    {
        /** @var $request BrowserKitRequest  **/
        $guzzleRequest = new Psr7Request(
            $request->getMethod(),
            $request->getUri(),
            $this->extractHeaders($request),
            $request->getContent()
        );

        $options = $this->requestOptions;
        $options['cookies'] = $this->extractCookies();
        $multipartData = $this->extractMultipartFormData($request);
        if (!empty($multipartData)) {
            $options['multipart'] = $multipartData;
        }

        $formData = $this->extractFormData($request);
        if (empty($multipartData) and $formData) {
            $options['form_params'] = $formData;
        }

        try {
            $response = $this->client->send($guzzleRequest, $options);
        } catch (ConnectException $e) {
            $url = (string) $this->client->getConfig('base_uri');
            throw new ConnectionException("Couldn't connect to $url. Please check that web server is running");
        } catch (RequestException $e) {
            if (!$e->hasResponse()) {
                throw $e;
            }
            $response = $e->getResponse();
        }
        return $this->createResponse($response);
    }

    protected function extractHeaders(BrowserKitRequest $request)
    {
        $headers = [];
        $server = $request->getServer();

        $uri = new Psr7Uri($request->getUri());
        $server['HTTP_HOST'] = $uri->getHost();
        $port = $uri->getPort();
        if ($port !== null && $port !== 443 && $port != 80) {
            $server['HTTP_HOST'] .= ':' . $port;
        }

        $contentHeaders = ['Content-Length' => true, 'Content-Md5' => true, 'Content-Type' => true];
        foreach ($server as $header => $val) {
            $header = implode('-', array_map('ucfirst', explode('-', strtolower(str_replace('_', '-', $header)))));
            if (strpos($header, 'Http-') === 0) {
                $headers[substr($header, 5)] = $val;
            } elseif (isset($contentHeaders[$header])) {
                $headers[$header] = $val;
            }
        }
        return $headers;
    }

    protected function extractFormData(BrowserKitRequest $request)
    {
        if (!in_array(strtoupper($request->getMethod()), ['POST', 'PUT', 'PATCH'])) {
            return null;
        }

        // guessing if it is a form data
        $headers = $request->getServer();
        if (isset($headers['HTTP_CONTENT_TYPE'])) {
            // not a form
            if ($headers['HTTP_CONTENT_TYPE'] !== 'application/x-www-form-urlencoded') {
                return null;
            }
        }
        if ($request->getContent() !== null) {
            return null;
        }
        return $request->getParameters();
    }

    protected function extractMultipartFormData(Request $request)
    {
        if (!in_array(strtoupper($request->getMethod()), ['POST', 'PUT', 'PATCH'])) {
            return [];
        }

        $parts = $this->mapFiles($request->getFiles());
        if (empty($parts)) {
            return [];
        }

        foreach ($request->getParameters() as $k => $v) {
            $parts = $this->formatMultipart($parts, $k, $v);
        }
        return $parts;
    }

    protected function formatMultipart($parts, $key, $value)
    {
        if (is_array($value)) {
            foreach ($value as $subKey => $subValue) {
                $parts = array_merge($this->formatMultipart([], $key."[$subKey]", $subValue), $parts);
            }
            return $parts;
        }
        $parts[] = ['name' => $key, 'contents' => $value];
        return $parts;
    }

    protected function mapFiles($requestFiles, $arrayName = '')
    {
        $files = [];
        foreach ($requestFiles as $name => $info) {
            if (!empty($arrayName)) {
                $name = $arrayName . '[' . $name . ']';
            }

            if (is_array($info)) {
                if (isset($info['tmp_name'])) {
                    if ($info['tmp_name']) {
                        $handle = fopen($info['tmp_name'], 'r');
                        $filename = isset($info['name']) ? $info['name'] : null;

                        $files[] = [
                            'name' => $name,
                            'contents' => $handle,
                            'filename' => $filename
                        ];
                    }
                } else {
                    $files = array_merge($files, $this->mapFiles($info, $name));
                }
            } else {
                $files[] = [
                    'name' => $name, 
                    'contents' => fopen($info, 'r')
                ];
            }
        }

        return $files;
    }
    
    protected function extractCookies()
    {
        $jar = [];
        $cookies = $this->getCookieJar()->all();
        foreach ($cookies as $cookie) {
            /** @var $cookie Cookie  **/
            $setCookie = SetCookie::fromString((string)$cookie);
            if (!$setCookie->getDomain()) {
                $setCookie->setDomain('localhost');
            }
            $jar[] = $setCookie;
        }
        return new CookieJar(true, $jar);
    }
}
