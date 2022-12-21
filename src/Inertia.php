<?php

namespace Inertia;

use SilverStripe\Core\Injector\Injector;
use SilverStripe\View\TemplateGlobalProvider;

class Inertia implements TemplateGlobalProvider
{
    public static function __callStatic($method, $args)
    {
        return Injector::inst()->get(ResponseFactory::class)->$method(...$args);
    }

    public static function renderApp($pageJson)
    {
        return "<div id='app' data-page='{$pageJson}'></div>";
    }

    public static function get_template_global_variables()
    {
        return [
            'inertia' => [
                'method' => 'renderApp',
                'casting' => 'HTMLText'
            ]
        ];
    }
}
