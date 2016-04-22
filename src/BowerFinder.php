<?php

/**
 * Class BowerFinder
 */
class BowerFinder
{
    /** @var string */
    protected $bower_path = '.';
    /** @var array */
    protected $bower_data;
    /** @var null|string path where bower components are, needs to be detected (.bowerrc) */
    protected $component_path;
    /**@var null|array initialized through loadInstalledComponents */
    protected $installed_components;

    /**
     * @return array
     */
    public function getBowerData()
    {
        if (is_null($this->bower_data)) {
            $this->loadBowerData();
        }

        return $this->bower_data;
    }

    /**
     * @param array $bower_data
     *
     * @return $this
     */
    public function setBowerData(array $bower_data)
    {
        $this->bower_data = $bower_data;

        return $this;
    }

    /**
     * @return $this
     */
    protected function loadBowerData()
    {
        $data = $this->loadJsonFile(
            $this->concatPaths(
                $this->getBowerPath(),
                'bower.json'
            ),
            true
        );

        $data += array(
            'dependencies' => array(),
            'components' => array(),
        );

        $this->bower_data = $data;

        return $this;
    }

    /**
     * @param string $file
     * @param bool $assoc
     * @param int $depth
     * @param int $options
     *
     * @throws RuntimeException
     * @throws LogicException
     *
     * @return mixed
     */
    protected function loadJsonFile($file, $assoc = false, $depth = 512, $options = 0)
    {
        $base_error = 'Can\'t find load JSON in file: ' . $file . ', ';
        if (!file_exists($file)) {
            throw new LogicException($base_error . 'does not exist.');
        }

        $file_content = file_get_contents($file);

        /**
         * Support iso to UTF-8
         */
        if (!mb_detect_encoding($file_content, 'UTF-8', true)) {
            $file_content = mb_convert_encoding($file_content, 'UTF-8', mb_detect_encoding($file_content, 'auto'));
            if (!mb_detect_encoding($file_content, 'UTF-8', true)) {
                throw new \RuntimeException($base_error . ' is not UTF-8 compatible or can not be converted to UTF-8.');
            }
        }

        /**
         * support for different parameters.
         */
        if (version_compare(PHP_VERSION, '5.4.0') >= 0) {
            $data = json_decode($file_content, $assoc, $depth, $options);
        } elseif (version_compare(PHP_VERSION, '5.3.0') >= 0) {
            $data = json_decode($file_content, $assoc, $depth);
        } else {
            $data = json_decode($file_content, $assoc);
        }

        if (json_last_error()) {
            throw new RuntimeException($base_error . 'json error:' . json_last_error_msg(), json_last_error());
        }

        return $data;
    }

    /**
     * @param string $base_path
     * @param string $path,...
     *
     * @return string
     */
    protected function concatPaths($base_path, $path)
    {

        return preg_replace('@(^|/)\./@', '', implode('/', array_map(function ($path) {
            return rtrim(preg_replace('@[\\/]{2,}@', '/', $path), '/');
        }, func_get_args())));
    }

    /**
     * @return string
     */
    public function getBowerPath()
    {
        return $this->bower_path;
    }

    /**
     * @param string string
     *
     * @return BowerFinder
     */
    public function setBowerPath($bower_path = null)
    {
        if (!$bower_path) {
            $bower_path = '.';
        }
        $this->bower_path = $bower_path;

        return $this;
    }

    /**
     * @see getDependentFilesForComponents
     * @param string|array $component_name
     * @param callable|string|null $filter
     * @return array
     */
    public function getDependentFilesForComponent($component_name, $filter = null)
    {
        return $this->getDependentFilesForComponents((array)$component_name, $filter);
    }

    /**
     * @param string|array $required_component_names
     * @param callable|string|null $filter
     * @return array
     */
    public function getDependentFilesForComponents($required_component_names, $filter = null)
    {
        if (is_string($required_component_names)) {
            $required_component_names = preg_split('@[,\s;]+@', $required_component_names);
        } elseif (!is_array($required_component_names)) {
            throw new \LogicException('$required_component_names must be a string or array, got ' .
                var_export($required_component_names, true));
        }

        if (!$filter) {
            $filter = '@.*@';
        }

        $files = array();
        $handled_dependencies = array();

        foreach ($required_component_names as $required_component_name) {
            $dependencies = $this->getComponentDependencies($required_component_name);
            $dependencies[$required_component_name] = true;
            foreach ($dependencies as $dependency_name => $version) {
                if (!isset($handled_dependencies[$dependency_name])) {
                    $handled_dependencies[$dependency_name] = true;
                    $component = $this->getComponent($dependency_name);

                    foreach ($component['main'] as $f) {
                        if (is_callable($filter) ? call_user_func($filter, $f, $component) : preg_match($filter, $f)) {
                            $files[] = $this->concatPaths($component['directory'], $f);
                        }
                    }
                }
            }
        }

        return $files;
    }

    /**
     * @param string $name
     *
     * @return array
     */
    public function getComponentDependencies($name)
    {
        $component = $this->getComponent($name);
        $dependencies = $component['dependencies'];

        foreach ($component['dependencies'] as $dependency_name => $version) {
            $dependencies = array_merge($this->getComponentDependencies($dependency_name), $dependencies);
        }

        return $dependencies;
    }

    /**
     * @param $name
     *
     * @return mixed
     */
    public function getComponent($name)
    {
        $components = $this->getInstalledComponents();
        if (!isset($components[$name])) {
            throw new \RuntimeException('Component ' . $name . ' is not installed. Installed are: '
                . implode(', ', array_keys($components)));
        }

        return $components[$name];
    }

    /**
     * @return array
     */
    public function getInstalledComponents()
    {
        if (is_null($this->installed_components)) {
            $this->loadInstalledComponents();
        }

        return $this->installed_components;
    }

    /**
     * @param array $installed_components
     *
     * @return BowerFinder
     */
    public function setInstalledComponents(array $installed_components)
    {
        $this->installed_components = $installed_components;

        return $this;
    }

    /**
     * @return BowerFinder
     */
    public function loadInstalledComponents()
    {
        if (!is_dir($this->getComponentPath())) {
            throw new \RuntimeException('Please run bower update or bower install, component path not found: ' . $this->getComponentPath());
        }

        $pattern = $this->concatPaths($this->getComponentPath(), '*/.bower.json');
        $meta_files = glob($pattern);

        $installed_components = array();

        if ($meta_files) {
            foreach ($meta_files as $meta_file) {
                $directory = dirname($meta_file);
                $component_dirname = basename($directory);
                $component_data = $this->loadJsonFile($meta_file, true);

                // cleanup...
                $component_data += array(
                    'directory' => $directory,
                    'dependencies' => array(),
                    'main' => array(),
                );
                if (is_string($component_data['main'])) {
                    $component_data['main'] = array($component_data['main']);
                }
                $installed_components[$component_dirname] = $component_data;
                if (isset($component_data['name']) && !isset($installed_components[$component_data['name']])) {
                    $installed_components[$component_data['name']] = $component_data;
                }
            }
        }
        $this->installed_components = $installed_components;

        return $this;
    }

    /**
     * @return null|string
     */
    public function getComponentPath()
    {
        if (!is_string($this->component_path)) {
            $this->loadComponentPath();
        }

        return $this->component_path;
    }

    /**
     * @param $component_path
     *
     * @return $this
     */
    public function setComponentPath($component_path)
    {
        $this->component_path = $component_path;

        return $this;
    }

    /**
     * @return $this
     */
    public function loadComponentPath()
    {
        try {
            $rc_data = $this->loadJsonFile($this->concatPaths($this->getBowerPath(), '.bowerrc'), true);
            if (isset($rc_data['directory'])) {
                $this->component_path = $this->concatPaths($this->getBowerPath(), $rc_data['directory']);
            }
        } catch (Exception $e) {
            //do nothing :)
        }
        if (!$this->component_path) {
            $this->component_path = $this->concatPaths($this->getBowerPath(), 'bower_components');
        }

        return $this;
    }
}
