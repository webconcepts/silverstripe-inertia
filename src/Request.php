<?php

namespace Inertia;

use SilverStripe\Control\HTTPRequest;

class Request extends HTTPRequest
{
    public static function createFrom(HTTPRequest $original)
    {
        $request = static::duplicateRequest($original);

        if (
            in_array($request->httpMethod(), ['POST', 'PUT', 'PATCH'])
            && strtolower($request->getHeader('Content-Type')) == 'application/json'
        ) {
            // copy data from json body into post vars of request
            $jsonData = json_decode($original->body, true) ?: [];
            $request->postVars = array_merge($request->postVars, $jsonData);
        }

        return $request;
    }

    protected static function duplicateRequest(HTTPRequest $original)
    {
        $request = new static(
            $original->httpMethod,
            $original->getUrl(),
            $original->getVars,
            $original->postVars,
            $original->body,
            $original->scheme,
        );

        $request->dirParts = $original->dirParts;
        $request->ip = $original->ip;
        $request->headers = $original->headers;
        $request->allParams = $original->allParams;
        $request->latestParams = $original->latestParams;
        $request->routeParams = $original->routeParams;
        $request->unshiftedButParsedParts = $original->unshiftedButParsedParts;
        $request->session = $original->session;

        return $request;
    }
}
