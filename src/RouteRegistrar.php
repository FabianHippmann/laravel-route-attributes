<?php

namespace Spatie\RouteAttributes;

use Illuminate\Routing\Router;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use Spatie\RouteAttributes\Attributes\Defaults;
use Spatie\RouteAttributes\Attributes\Fallback;
use Spatie\RouteAttributes\Attributes\Route;
use Spatie\RouteAttributes\Attributes\RouteAttribute;
use Spatie\RouteAttributes\Attributes\ScopeBindings;
use Spatie\RouteAttributes\Attributes\WhereAttribute;
use SplFileInfo;
use Symfony\Component\Finder\Finder;
use Throwable;

class RouteRegistrar
{
    private Router $router;

    protected string $basePath;

    protected string $rootNamespace;

    protected array $middleware = [];

    public function __construct(Router $router)
    {
        $this->router = $router;

        $this->useBasePath(app()->path());
    }

    public function group(array $options, $routes): self
    {
        $this->router->group($options, $routes);

        return $this;
    }

    public function useBasePath(string $basePath): self
    {
        $this->basePath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $basePath);

        return $this;
    }

    public function useRootNamespace(string $rootNamespace): self
    {
        $this->rootNamespace = rtrim(str_replace('/', '\\', $rootNamespace), '\\') . '\\';

        return $this;
    }

    public function useMiddleware(string | array $middleware): self
    {
        $this->middleware = Arr::wrap($middleware);

        return $this;
    }

    public function middleware(): array
    {
        return $this->middleware ?? [];
    }

    public function registerDirectory(string | array $directories): void
    {
        $directories = Arr::wrap($directories);

        $files = (new Finder())->files()->name('*.php')->in($directories)->sortByName();

        collect($files)->each(fn (SplFileInfo $file) => $this->registerFile($file));
    }

    public function registerFile(string | SplFileInfo $path): void
    {
        if (is_string($path)) {
            $path = new SplFileInfo($path);
        }

        $fullyQualifiedClassName = $this->fullQualifiedClassNameFromFile($path);

        $this->processAttributes($fullyQualifiedClassName);
    }

    public function registerClass(string $class): void
    {
        $this->processAttributes($class);
    }

    protected function fullQualifiedClassNameFromFile(SplFileInfo $file): string
    {
        $class = trim(Str::replaceFirst($this->basePath, '', $file->getRealPath()), DIRECTORY_SEPARATOR);

        $class = str_replace(
            [DIRECTORY_SEPARATOR, 'App\\'],
            ['\\', app()->getNamespace()],
            ucfirst(Str::replaceLast('.php', '', $class))
        );

        return $this->rootNamespace . $class;
    }

    protected function processAttributes(string $className): void
    {
        if (! class_exists($className)) {
            return;
        }

        $class = new ReflectionClass($className);

        $classRouteAttributes = new ClassRouteAttributes($class);

        if ($classRouteAttributes->resource()) {
            $this->registerResource($class, $classRouteAttributes);
        }

        $groups = $classRouteAttributes->groups();

        foreach ($groups as $group) {
            $router = $this->router;
            $router->group($group, fn () => $this->registerRoutes($class, $classRouteAttributes));
        }
    }

    protected function registerResource(ReflectionClass $class, ClassRouteAttributes $classRouteAttributes): void
    {
        $this->router->group([
            'domain' => $classRouteAttributes->domain(),
            'prefix' => $classRouteAttributes->prefix(),
        ], $this->getRoutes($class, $classRouteAttributes));
    }

    protected function registerAttribute(ReflectionClass $class, ClassRouteAttributes $classRouteAttributes, ReflectionMethod|ReflectionClass $method){
        list($attributes, $wheresAttributes, $defaultAttributes, $fallbackAttributes, $scopeBindingsAttribute) = $this->getAttributesForReflector($method);


        foreach ($attributes as $attribute) {
            try {
                $attributeClass = $attribute->newInstance();
            } catch (Throwable) {
                continue;
            }

            if (! $attributeClass instanceof Route) {
                continue;
            }


            list($httpMethods, $action) = $this->getHTTPMethodsAndAction($attributeClass, $method, $class);


            $route = $this->router->addRoute($httpMethods, $attributeClass->uri, $action)->name($attributeClass->name);


            $this->setScopeBindingsIfAvailable($scopeBindingsAttribute, $route, $classRouteAttributes);


            $this->setWheresIfAvailable($classRouteAttributes, $wheresAttributes, $route);


            $this->setDefaultsIfAvailable($classRouteAttributes, $defaultAttributes, $route);


            $this->addMiddlewareToRoute($classRouteAttributes, $attributeClass, $route);


            if (count($fallbackAttributes) > 0) {
                $route->fallback();
            }
        }
    }
    protected function registerRoutes(ReflectionClass $class, ClassRouteAttributes $classRouteAttributes): void
    {
        $this->registerAttribute($class, $classRouteAttributes, $class);

        foreach ($class->getMethods() as $method) {
            $this->registerAttribute($class, $classRouteAttributes, $method);
        }
    }

    /**
     * @param ReflectionAttribute|null $scopeBindingsAttribute
     * @param \Illuminate\Routing\Route $route
     * @param ClassRouteAttributes $classRouteAttributes
     * @return void
     */
    public function setScopeBindingsIfAvailable(?ReflectionAttribute $scopeBindingsAttribute, \Illuminate\Routing\Route $route, ClassRouteAttributes $classRouteAttributes): void
    {
        if ($scopeBindingsAttribute) {
            $scopeBindingsAttributeClass = $scopeBindingsAttribute->newInstance();

            if ($scopeBindingsAttributeClass->scopeBindings) {
                $route->scopeBindings();
            }
        } elseif ($classRouteAttributes->scopeBindings()) {
            $route->scopeBindings();
        }
    }

    /**
     * @param \ReflectionMethod $reflector
     * @return array
     */
    public function getAttributesForReflector(\ReflectionClass|\ReflectionMethod $reflector): array
    {
        $attributes = $reflector->getAttributes(RouteAttribute::class, ReflectionAttribute::IS_INSTANCEOF);
        $wheresAttributes = $reflector->getAttributes(WhereAttribute::class, ReflectionAttribute::IS_INSTANCEOF);
        $defaultAttributes = $reflector->getAttributes(Defaults::class, ReflectionAttribute::IS_INSTANCEOF);
        $fallbackAttributes = $reflector->getAttributes(Fallback::class, ReflectionAttribute::IS_INSTANCEOF);
        $scopeBindingsAttribute = $reflector->getAttributes(ScopeBindings::class, ReflectionAttribute::IS_INSTANCEOF)[0] ?? null;

        return [$attributes, $wheresAttributes, $defaultAttributes, $fallbackAttributes, $scopeBindingsAttribute];
    }

    /**
     * @param ClassRouteAttributes $classRouteAttributes
     * @param mixed $wheresAttributes
     * @param \Illuminate\Routing\Route $route
     * @return void
     */
    public function setWheresIfAvailable(ClassRouteAttributes $classRouteAttributes, mixed $wheresAttributes, \Illuminate\Routing\Route $route): void
    {
        $wheres = $classRouteAttributes->wheres();
        foreach ($wheresAttributes as $wheresAttribute) {
            $wheresAttributeClass = $wheresAttribute->newInstance();
            $wheres[$wheresAttributeClass->param] = $wheresAttributeClass->constraint;
        }
        if (! empty($wheres)) {
            $route->setWheres($wheres);
        }
    }

    /**
     * @param Route $attributeClass
     * @param \ReflectionMethod $method
     * @param ReflectionClass $class
     * @return array
     */
    public function getHTTPMethodsAndAction(Route $attributeClass, \ReflectionMethod|ReflectionClass $method, ReflectionClass $class): array
    {
        $httpMethods = $attributeClass->methods;
        $isSingleAction = $method instanceof ReflectionClass;

        if($isSingleAction){
            $action = $class->getName();
        } else {
            $action = $method->getName() === '__invoke' ? $class->getName() : [$class->getName(), $method->getName()];
        }

        return [$httpMethods, $action];
    }

    /**
     * @param ClassRouteAttributes $classRouteAttributes
     * @param Route $attributeClass
     * @param \Illuminate\Routing\Route $route
     * @return void
     */
    public function addMiddlewareToRoute(ClassRouteAttributes $classRouteAttributes, Route $attributeClass, \Illuminate\Routing\Route $route): void
    {
        $classMiddleware = $classRouteAttributes->middleware();
        $methodMiddleware = $attributeClass->middleware;
        $route->middleware([...$this->middleware, ...$classMiddleware, ...$methodMiddleware]);
    }

    /**
     * @param ClassRouteAttributes $classRouteAttributes
     * @param mixed $defaultAttributes
     * @param \Illuminate\Routing\Route $route
     * @return void
     */
    public function setDefaultsIfAvailable(ClassRouteAttributes $classRouteAttributes, mixed $defaultAttributes, \Illuminate\Routing\Route $route): void
    {
        $defaults = $classRouteAttributes->defaults();
        foreach ($defaultAttributes as $defaultAttribute) {
            $defaultAttributeClass = $defaultAttribute->newInstance();

            $defaults[$defaultAttributeClass->key] = $defaultAttributeClass->value;
        }
        if (! empty($defaults)) {
            $route->setDefaults($defaults);
        }
    }

    /**
     * @param ReflectionClass $class
     * @param ClassRouteAttributes $classRouteAttributes
     * @return \Closure
     */
    public function getRoutes(ReflectionClass $class, ClassRouteAttributes $classRouteAttributes): \Closure
    {
        return function () use ($class, $classRouteAttributes) {
            $route = $classRouteAttributes->apiResource()
                ? $this->router->apiResource($classRouteAttributes->resource(), $class->getName())
                : $this->router->resource($classRouteAttributes->resource(), $class->getName());

            $methods = [
                'only',
                'except',
                'names',
                'parameters',
                'shallow',
            ];

            foreach ($methods as $method) {
                $value = $classRouteAttributes->$method();

                if ($value !== null) {
                    $route->$method($value);
                }
            }

            $route->middleware([...$this->middleware, ...$classRouteAttributes->middleware()]);
        };
    }
}
