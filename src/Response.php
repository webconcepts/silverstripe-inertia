<?php

namespace Inertia;

use Closure;
use JsonSerializable;
use SilverStripe\ORM\SS_List;
use SilverStripe\ORM\DataObject;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPResponse;

class Response
{
    protected $component;

    protected $props;

    protected $rootTemplate;

    protected $version;

    protected $viewData = [];

    public function __construct($component, $props, $rootTemplate = null, $version = null)
    {
        $this->component = $component;
        $this->props = $props;
        $this->rootTemplate = $rootTemplate;
        $this->version = $version;
    }

    public function with($key, $value = null)
    {
        if (is_array($key)) {
            $this->props = array_merge($this->props, $key);
        } else {
            $this->props[$key] = $value;
        }

        return $this;
    }

    public function withViewData($key, $value = null)
    {
        if (is_array($key)) {
            $this->viewData = array_merge($this->viewData, $key);
        } else {
            $this->viewData[$key] = $value;
        }

        return $this;
    }

    // todo make silverstripe
    // should be called by controllers next step ?
    public function toResponse()
    {
        $request = $this->request();
        $partialData = $request->getHeader('X-Inertia-Partial-Data');
        $only = array_filter(
            explode(',', $partialData ? $partialData->getValue() : '')
        );

        $partialComponent = $request->getHeader('X-Inertia-Partial-Component');
        $props = ($only && ($partialComponent ? $partialComponent->getValue() : '') === $this->component)
            ? Helpers::arrayOnly($this->props, $only)
            : $this->props;

        $props = $this->resolvePropertyValues($props);

        $page = [
            'component' => $this->component,
            'props' => $props,
            'url' => $request->getURL(),
            'version' => $this->version ? $this->version : 0,
        ];
        $json = json_encode($page);

        $response = new HTTPResponse();
        if ($request->getHeader('X-Inertia')) {
            $response->setBody($json);
            $response->addHeader('Vary', 'Accept');
            $response->addHeader('X-Inertia','true');
            return $response;
        } else {
            $controller = Controller::curr();
            if ($this->rootTemplate) {
                $processed = $controller->renderWith($this->rootTemplate, $this->viewData + ['page' => $page, 'pageJson' => $json]);
            } else {
                $processed = $controller->render($this->viewData + ['page' => $page, 'pageJson' => $json]);
            }
            $response->setBody($processed);
            return $response;
        }
    }

    public function request()
    {
        $controller = Controller::curr();
        return $controller->getRequest();
            }

    protected function resolvePropertyValues(array $props)
    {
        foreach ($props as $key => $value) {
            if ($value instanceof Closure) {
                $value = $value();
        }

            if ($value instanceof JsonSerializable) {
                $props[$key] = $value;
                continue;
    }

            if ($value instanceof DataObject) {
                $value = $value->toMap();
            }

            if ($value instanceof SS_List) {
                $value = $value->toArray();
            }

            if (is_array($value)) {
                $value = $this->resolvePropertyValues($value);
            }

            $props[$key] = $value;
        }

        return $props;
    }
}
