<?php

namespace Inertia;

use SilverStripe\Control\Cookie;
use SilverStripe\Control\Director;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Security\SecurityToken;
use SilverStripe\Forms\Schema\FormSchema;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Control\Middleware\HTTPMiddleware;
use SilverStripe\Core\Manifest\ModuleResourceLoader;

class Middleware implements HTTPMiddleware
{
    use Configurable;

    private static $app_bundle_resource;

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(HTTPRequest $request)
    {
        $resource = static::config()->app_bundle_resource;

        if ($resource && $path = ModuleResourceLoader::resourcePath($resource)) {
            return filemtime(Director::getAbsFile($path));
        }

        return null;
    }

    /**
     * Define the props that are shared by default
     *
     * @see https://inertiajs.com/shared-data
     */
    public function share(HTTPRequest $request)
    {
        return [
            'errors' => function () use ($request) {
                return $this->resolveValidationErrors($request);
            },
        ];
    }

    public function process(HTTPRequest $request, callable $delegate)
    {
        Inertia::version(function () use ($request) {
            return $this->version($request);
        });

        Inertia::share($this->share($request));

        $securityToken = SecurityToken::inst();

        if ($securityToken->isEnabled() && $request->getHeader('X-XSRF-TOKEN')) {
            $this->addXsrfTokenFromAxiosToRequest($request);
        }

        if ($request->getHeader('X-Inertia')) {
            $request = Request::createFrom($request);
        }

        $response = $delegate($request);
        $response->addHeader('Vary', 'X-Inertia');

        if ($securityToken->isEnabled() && !headers_sent() && !Director::is_cli()) {
            $this->setXsrfTokenCookieForAxios();
        }

        if (!$request->getHeader('X-Inertia')) {
            return $response;
        }

        if ($request->httpMethod() === 'GET' && $request->getHeader('X-Inertia-Version', '') !== Inertia::getVersion()) {
            $response = $this->onVersionChange($request, $response);
        }

        if ($response->getStatusCode() === 200 && empty($response->getBody())) {
            $response = $this->onEmptyResponse($request, $response);
        }

        if ($response->getStatusCode() === 302 && in_array($request->httpMethod(), ['PUT', 'PATCH', 'DELETE'])) {
            $response->setStatusCode(303);
        }

        return $response;
    }

    /**
     * Determines what to do when an Inertia action returned with no response.
     * By default, we'll redirect the user back to where they came from.
     */
    public function onEmptyResponse(HTTPRequest $request, HTTPResponse $response): HTTPResponse
    {
        return Controller::curr()->redirectBack();
    }

    /**
     * Determines what to do when the Inertia asset version has changed.
     * By default, we'll initiate a client-side location visit to force an update.
     */
    public function onVersionChange(HTTPRequest $request, HTTPResponse $response): HTTPResponse
    {
        return Inertia::location(
            Director::absoluteURL($request->getURL(true))
        );
    }

    /**
     * Copy the security token that Axois provides in the X-XSRF-TOKEN header,
     * to the X-SecurityID header that SilverStripe's security token check
     * will look for.
     */
    protected function addXsrfTokenFromAxiosToRequest(HTTPRequest $request)
    {
        $securityTokenName = SecurityToken::inst()->getName();

        $request->addHeader("X-{$securityTokenName}", $request->getHeader('X-XSRF-TOKEN'));
    }

    /**
     * Provide a XSRF-TOKEN cookie for Axios to have it automatically include
     * the token with each request it makes, in the X-XSRF-TOKEN header
     */
    protected function setXsrfTokenCookieForAxios()
    {
        Cookie::set(
            name: 'XSRF-TOKEN',
            value: SecurityToken::inst()->getValue(),
            httpOnly: false
        );
    }

    protected function resolveValidationErrors(HTTPRequest $request): object
    {
        $formInfo = $request->hasSession() ? $request->getSession()->get('FormInfo') : [];
        $errors = [];

        foreach ((array) $formInfo as $formName => $data) {
            if (empty($data['result'])) {
                continue;
            }

            foreach ((new FormSchema())->getErrors(unserialize($data['result'])) as $error) {
                if (!empty($error['field']) && !empty($error['value'])) {
                    $errors[$error['field']] = $error['value'];
                }
            }
        }

        if ($request->getHeader('x-inertia-error-bag') && !empty($errors)) {
            return (object) [$request->header('x-inertia-error-bag') => $errors];
        }

        return (object) $errors;
    }
}
