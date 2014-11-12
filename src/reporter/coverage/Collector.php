<?php
namespace kahlan\reporter\coverage;

use dir\Dir;
use kahlan\jit\Interceptor;

class Collector
{
    /**
     * Stack of active collectors.
     *
     * @var array
     */
    protected static $_collectors = [];

    /**
     * Class dependencies.
     *
     * @var array
     */
    protected $_classes = [
        'parser' => 'kahlan\analysis\Parser',
    ];

    /**
     * The driver instance which will log the coverage data.
     *
     * @var object
     */
    protected $_driver = null;

    /**
     * The path(s) which contain the code source files.
     *
     * @var array
     */
    protected $_paths = [];

    /**
     * Some prefix to remove to get the real file path.
     *
     * @var string
     */
    protected $_prefix = '';

    /**
     * The files presents in `Collector::_paths`.
     *
     * @var array
     */
    protected $_files = [];

    /**
     * The coverage data.
     *
     * @var array
     */
    protected $_coverage = [];

    /**
     * The metrics.
     *
     * @var array
     */
    protected $_metrics = [];

    /**
     * Cache all parsed files
     *
     * @var array
     */
    protected $_tree = [];

    /**
     * Constructor.
     *
     * @param array $options Possible options values are:
     *              - `'driver'`: the driver instance which will log the coverage data.
     *              - `'path'`  : the path(s) which contain the code source files.
     *              - `'base'`  : the base path of the repo (default: `getcwd`).
     *              - `'prefix'`: some prefix to remove to get the real file path.
     */
    public function __construct($options = [])
    {
        $defaults = [
            'driver'         => null,
            'path'           => [],
            'include'        => '*.php',
            'exclude'        => [],
            'type'           => 'file',
            'skipDots'       => true,
            'leavesOnly'     => false,
            'followSymlinks' => true,
            'recursive'      => true,
            'base'           => str_replace(DIRECTORY_SEPARATOR, '/', getcwd()),
            'prefix'         => rtrim(Interceptor::instance()->cache(), DS)
        ];
        $options += $defaults;

        $this->_driver = $options['driver'];
        $this->_paths = (array) $options['path'];
        $this->_base = $options['base'];
        $this->_prefix = $options['prefix'];

        $files = Dir::scan($options);
        foreach ($files as $file) {
            $this->_coverage[realpath($file)] = [];
        }
    }

    /**
     * Return the used driver.
     *
     * @return object
     */
    public function driver() {
        return $this->_driver;
    }

    /**
     * Return the base path used to compute relative paths.
     *
     * @return string
     */
    public function base() {
        return rtrim($this->_base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }

    /**
     * Start collecting coverage data.
     *
     * @return boolean
     */
    public function start()
    {
        if ($collector = end(static::$_collectors)) {
            $collector->add($collector->_driver->stop());
        }
        static::$_collectors[] = $this;
        $this->_driver->start();
        return true;
    }

    /**
     * Stop collecting coverage data.
     *
     * @return boolean
     */
    public function stop($mergeToParent = true)
    {
        $collector = end(static::$_collectors);
        $collected = [];
        if ($collector !== $this) {
            return false;
        }
        array_pop(static::$_collectors);
        $collected = $this->_driver->stop();
        $this->add($collected);

        $collector = end(static::$_collectors);
        if (!$collector) {
            return true;
        }
        $collector->add($mergeToParent ? $collected : []);
        $collector->_driver->start();
        return true;
    }

    /**
     * Add some coverage data to the collector.
     *
     * @param  array $coverage Some coverage data.
     * @return array The current coverage data.
     */
    public function add($coverage)
    {
        if (!$coverage) {
            return;
        }
        foreach ($coverage as $file => $data) {
            $this->addFile($file, $data);
        }
        return $this->_coverage;
    }

    /**
     * Add some coverage data to the collector.
     *
     * @param  string $file     A file path.
     * @param  array  $coverage Some coverage related to the file path.
     */
    public function addFile($file, $coverage)
    {
        $file = $this->realpath($file);
        if (!$this->collectable($file)) {
            return;
        }
        $nbLines = count(file($file));

        foreach ($coverage as $line => $value) {
            if ($line === 0 || $line >= $nbLines) {
                continue; // Because Xdebug bugs...
            }
            if (!isset($this->_coverage[$file][$line])) {
                $this->_coverage[$file][$line] = $value;
            } else {
                $this->_coverage[$file][$line] += $value;
            }
        }
    }

    protected function _coverage($file, $coverage)
    {
        $result = [];
        $root = $this->parse($file);
        foreach ($root->lines['content'] as $num => $nodes) {
            $coverable = null;
            foreach ($nodes as $node) {
                if ($node->coverable && $node->lines['stop'] === $num) {
                    $coverable = $node;
                    break;
                }
            }
            if (!$coverable) {
                continue;
            }
            if (isset($coverage[$num])) {
                $result[$num] = $coverage[$num];
            } else {
                $result[$num] = 0;
            }
        }
        return $result;
    }

    /**
     * Check if a filename is collectable.
     *
     * @param   string  $file A file path.
     * @return  boolean
     */
    public function collectable($file) {
        $file = $this->realpath($file);
        if (preg_match("/eval\(\)'d code$/", $file) || !isset($this->_coverage[$file])) {
            return false;
        }
        return true;
    }

    /**
     * Returns the real path in the original src directory.
     *
     * @param  string $file A file path or cached file path.
     * @return string       The original file path.
     */
    public function realpath($file) {
        $prefix = $this->_prefix;
        return preg_replace("~^{$prefix}~", '', $file);
    }

    /**
     * Return coverage data.
     *
     * @return array The coverage data.
     */
    public function export($file = null)
    {
        if (!$file) {
            $result = [];
            $base = $this->base();
            foreach ($this->_coverage as $file => $coverage) {
                $result[preg_replace("~^{$base}~", '', $file)] = $this->_coverage($file, $coverage);
            }
            return $result;
        }
        return isset($this->_coverage[$file]) ? $this->_coverage($file, $this->_coverage[$file]) : [];
    }


    /**
     * Return the collected metrics from coverage data.
     *
     * @return Metrics The collected metrics.
     */
    public function metrics()
    {
        $this->_metrics = new Metrics();
        foreach ($this->_coverage as $file => $xdebug) {
            $node = $this->parse($file);
            $coverage = $this->export($file);
            $this->_processTree($file, $node, $node->tree, $coverage);
        }
        return $this->_metrics;
    }

    /**
     * Helper for `Collector::metrics()`.
     *
     * @param  string  $file     The processed file.
     * @param  NodeDef $root     The root node of the processed file.
     * @param  NodeDef $nodes    The nodes to collect metrics on.
     * @param  array   $coverage The coverage data.
     * @param  string  $path     The naming of the processed node.
     */
    protected function _processTree($file, $root, $nodes, $coverage, $path = '')
    {
        foreach ($nodes as $node) {
            $this->_processNode($file, $root, $node, $coverage, $path);
        }
    }

    /**
     * Helper for `Collector::metrics()`.
     *
     * @param  string  $file     The processed file.
     * @param  NodeDef $root     The root node of the processed file.
     * @param  NodeDef $node     The node to collect metrics on.
     * @param  array   $coverage The coverage data.
     * @param  string  $path     The naming of the processed node.
     */
    protected function _processNode($file, $root, $node, $coverage, $path)
    {
        if ($node->type === 'class' || $node->type === 'namespace') {
            $path = "{$path}\\" . $node->name;
            $this->_processTree($file, $root, $node->tree, $coverage, $path);
        } elseif ($node->type === 'function' && !$node->isClosure) {
            $metrics = $this->_processMethod($file, $root, $node, $coverage);
            $prefix = $node->isMethod ? "{$path}::" : "{$path}\\";
            $this->_metrics->add(ltrim($prefix . $node->name . '()', '\\'), $metrics);
        } elseif (count($node->tree)) {
            $this->_processTree($file, $root, $node->tree, $coverage, $path);
        }
    }

    /**
     * Helper for `Collector::metrics()`.
     *
     * @param  string  $file     The processed file.
     * @param  NodeDef $root     The root node of the processed file.
     * @param  NodeDef $node     The node to collect metrics on.
     * @param  array   $coverage The coverage data.
     * @return array   The collected metrics.
     */
    protected function _processMethod($file, $root, $node, $coverage)
    {
        $metrics = [
            'loc' => 0,
            'ncloc' => 0,
            'cloc' => 0,
            'covered' => 0,
            'percent' => 0,
            'methods' => 1,
            'cmethods' => 0
        ];
        for ($line = $node->lines['start']; $line <= $node->lines['stop']; $line++) {
            $this->_processLine($line, $coverage, $metrics);
        }
        $metrics['files'][] = $file;
        $metrics['line'] = $node->lines['start'];
        $metrics['loc'] = ($node->lines['stop'] - $node->lines['start']) + 1;
        if ($metrics['covered']) {
            $metrics['cmethods'] = 1;
        }
        return $metrics;
    }

    /**
     * Helper for `Collector::metrics()`.
     *
     * @param int   $line     The line number to collect.
     * @param array $coverage The coverage data.
     * @param array $metrics  The output metrics array.
     */
    protected function _processLine($line, $coverage, &$metrics)
    {
        if (!$coverage) {
            return;
        }
        if (!isset($coverage[$line])) {
            $metrics['ncloc']++;
            return;
        }
        if ($coverage[$line]) {
            $metrics['covered']++;
        }
        $metrics['cloc']++;
    }

    /**
     * Retruns & cache the tree structure of a file.
     *
     * @param string $file the file path to use for building the tree structure.
     */
    public function parse($file)
    {
        if (isset($this->_tree[$file])) {
            return $this->_tree[$file];
        }
        $parser = $this->_classes['parser'];
        return $this->_tree[$file] = $parser::parse(file_get_contents($file), ['lines' => true]);
    }

}
