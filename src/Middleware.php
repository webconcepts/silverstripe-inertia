<?php

namespace Inertia;

use SilverStripe\Control\Middleware\HTTPMiddleware;
use SilverStripe\Control\Cookie;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Security\SecurityToken;
use SilverStripe\Forms\Schema\FormSchema;

class Middleware implements HTTPMiddleware
{
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
        Inertia::share($this->share($request));

        $securityToken = SecurityToken::inst();

        if ($securityToken->isEnabled() && $request->getHeader('X-XSRF-TOKEN')) {
            $this->addXsrfTokenFromAxiosToRequest($request);
        }

        if ($request->getHeader('X-Inertia')) {
            $request = Request::createFrom($request);
        }

        $response = $delegate($request);

        if ($securityToken->isEnabled() && !headers_sent() && !Director::is_cli()) {
            $this->setXsrfTokenCookieForAxios();
        }

        // If we have no X-Inertia header continue
        if (!$request->getHeader('X-Inertia')) {
            return $response;
        }

        $response->addHeader('Vary', 'Accept');
        $response->addHeader('X-Inertia', 'true');

        // Don't forget to the return the response!
        return $response;
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
