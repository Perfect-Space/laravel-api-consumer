<?php

namespace BlackBits\ApiConsumer\Support;

use BlackBits\ApiConsumer\CollectionCallbacks\_ReflectionCollectionCallback;
use BlackBits\ApiConsumer\Contracts\CollectionCallbackContract;
use BlackBits\ApiConsumer\Support\ShapeResolver;
use Zttp\Zttp;
use Illuminate\Support\Facades\Cache;

abstract class Endpoint
{
    private $basePath;
    private $shapeResolver;
    private $collectionCallbacks = [];

    protected $headers = [];
    protected $options = [];
    protected $basicAuth = ['username' => '', 'password' => ''];
    protected $path;
    protected $method;

    protected $shouldCache = false;
    protected $shouldUseBasicAuth = false;
    protected $shouldUseMultipartFormData = false;
    protected $shouldUseFormParams = false;
    protected $cacheDurationInMinutes = 5;


    /**
     * Endpoint constructor.
     * @param $basePath
     * @param ShapeResolver $shapeResolver
     */
    public function __construct($basePath, ShapeResolver $shapeResolver)
    {
        $this->basePath      = $basePath;
        $this->shapeResolver = $shapeResolver;
    }

    /**
     * @return string
     */
    private function uri()
    {
        return $this->basePath . "/" . ltrim($this->path, "/");
    }

    /**
     * @return string
     */
    private function getCacheKey()
    {
        $key = $this->method . "-" . $this->uri();

        if (!empty($this->options)) {
            $value = $this->options;
            if (is_array($value)) {
                $value = http_build_query($value, null, '&', PHP_QUERY_RFC3986);
            }
            if (is_string($value)) {
                $key .= "-" . $value;
            }
        }

        return $key;
    }

    /**
     * @return mixed
     */
    private function request()
    {
        $zttp = Zttp::withHeaders($this->headers);

        if ($this->shouldUseBasicAuth) {
            $zttp = $zttp->withBasicAuth($this->basicAuth['username'], $this->basicAuth['password']);
        }

        if (strtolower($this->method) == "get") {
            if ($this->shouldCache) {
                return Cache::remember($this->getCacheKey(), $this->cacheDurationInMinutes, function () use ($zttp) {
                    return $zttp->get($this->uri(), $this->options)->body();
                });
            }
            return $zttp->get($this->uri(), $this->options)->body();
        }

        if (strtolower($this->method) == "post") {
            if ($this->shouldUseMultipartFormData) {

                $multipart = [];

                foreach ($this->options as $key => $value) {
                    $multipart[] = ['name' => $key, 'contents' => $value];
                }

                $this->options = $multipart;
                $zttp = $zttp->asMultipart();

            } elseif ($this->shouldUseFormParams) {

                $zttp = $zttp->asFormParams();
            }

            return $zttp->post($this->uri(), $this->options)->body();
        }

        // TODO: other Methods
        return "[]";
    }

    /**
     * @param CollectionCallbackContract $collectionCallback
     */
    private function registerCollectionCallback(CollectionCallbackContract $collectionCallback)
    {
        $this->collectionCallbacks[] = $collectionCallback;
    }

    /**
     * @return \Illuminate\Support\Collection|\Tightenco\Collect\Support\Collection
     * @throws \Exception
     */
    final public function get()
    {
        $this->method = "GET";

        $collection = $this->shapeResolver->resolve($this->request());

        /** @var CollectionCallbackContract $callback */
        foreach ($this->collectionCallbacks as $callback) {
            $collection = $callback->applyTo($collection);
        }

        return $collection;
    }

    /**
     * @return \Illuminate\Support\Collection|\Tightenco\Collect\Support\Collection
     * @throws \Exception
     */
    final public function post()
    {
        $this->method = "POST";

        $collection = $this->shapeResolver->resolve($this->request());

        /** @var CollectionCallbackContract $callback */
        foreach ($this->collectionCallbacks as $callback) {
            $collection = $callback->applyTo($collection);
        }

        return $collection;
    }

    /**
     * @return \Illuminate\Support\Collection|\Tightenco\Collect\Support\Collection
     * @throws \Exception
     */
    final public function first()
    {
        return $this->get()->first();
    }

    /**
     * @param $name
     * @param $arguments
     * @return $this
     * @throws \Exception
     */
    public function __call($name, $arguments)
    {
        $collectionCallback =  "\App\CollectionCallbacks\\" . ucfirst($name) . "CollectionCallback";

        if (!class_exists($collectionCallback)) {
            $collectionCallback =  "\BlackBits\ApiConsumer\CollectionCallbacks\\" . ucfirst($name) . "CollectionCallback";
        }

        if (!class_exists($collectionCallback)) {
            $this->registerCollectionCallback(
                (new _ReflectionCollectionCallback(... $arguments))->setMethod($name)
            );
            return $this;
        }

        $this->registerCollectionCallback(new $collectionCallback(... $arguments));
        return $this;
    }
}
