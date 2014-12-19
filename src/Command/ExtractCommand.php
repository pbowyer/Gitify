<?php
namespace modmore\Gitify\Command;

use Exception;
use modContext;
use modmore\Gitify\BaseCommand;
use modmore\Gitify\Gitify;

use RecursiveIteratorIterator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

use xPDOObject;
use modResource;
use modCategory;
use modElement;

use RecursiveDirectoryIterator;
use SplFileInfo;

/**
 * Class BuildCommand
 *
 * Builds a MODX site from the files and configuration.
 *
 * @package modmore\Gitify\Command
 */
class ExtractCommand extends BaseCommand
{
    public $categories = array();

    protected function configure()
    {
        $this
            ->setName('extract')
            ->setDescription('Extracts data from the MODX site, and stores it in human readable files for editing and committing to a VCS.')

            /*->addOption(
                'skip-clear-cache',
                null,
                InputOption::VALUE_NONE,
                'When specified, it will skip clearing the cache after building.'
            )*/
        ;
    }

    /**
     * Runs the command.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        foreach ($this->config['data'] as $folder => $type) {
            switch (true) {
                case (!empty($type['type']) && $type['type'] == 'content'):
                    // "content" is a shorthand for contexts + resources
                    $output->writeln("- Extracting content into {$folder}...");
                    $this->extractContent(GITIFY_WORKING_DIR . $this->config['data_directory'] . $folder, $type);

                    break;

                case (!empty($type['class'])):
                    $output->writeln("- Extracting {$type['class']} into {$folder}...");
                    if (isset($type['package'])) {
                        $this->getPackage($type['package'], $type);
                    }
                    $this->extractObjects(GITIFY_WORKING_DIR . $this->config['data_directory'] . $folder, $type);

                    break;
            }
        }
        return 0;
    }

    /**
     * Loads the Content, handling uris for naming etc.
     *
     * @param $folder
     * @param $options
     */
    public function extractContent($folder, $options)
    {
        $extension = (isset($options['extension'])) ? $options['extension'] : '.html';

        // Empty the current data
        $this->modx->getCacheManager()->deleteTree($folder, array('extensions' => ''));

        // Grab the contexts
        $contexts = $this->modx->getIterator('modContext');
        foreach ($contexts as $context) {
            /** @var modContext $context */
            $contextKey = $context->get('key');

            // Grab the resources in the context
            $resources = $this->modx->getIterator('modResource', array('context_key' => $contextKey));
            foreach ($resources as $resource) {
                /** @var modResource $resource */
                $file = $this->generate($resource, $options);

                // Somewhat normalize uris into something we can use as file path that makes (human) sense
                $uri = $resource->uri;
                // Trim trailing slash if there is one
                if (substr($uri, -1) == '/')
                {
                    $uri = rtrim($uri, '/');
                }
                else
                {
                    // Get rid of the extension by popping off the last part, and adding just the alias back.
                    $uri = explode('/', $uri);
                    array_pop($uri);
                    $uri[] = $resource->alias;
                    $uri = implode('/', $uri);
                }

                if (empty($uri)) $uri = $resource->id;

                // Write the file
                $fn = $folder . DIRECTORY_SEPARATOR . $contextKey . DIRECTORY_SEPARATOR . $uri . $extension;
                $this->modx->cacheManager->writeFile($fn, $file);
            }
        }
    }

    /**
     * Loads all objects for a specified class, first clearing out the current data.
     *
     * @param $folder
     * @param $options
     */
    public function extractObjects($folder, array $options = array())
    {
        // Read the current files
        $before = $this->getAllFiles($options['path'] . $folder);
        $after = array();

        // Grab the stuff
        $c = $this->modx->newQuery($options['class']);
        if (isset($options['where'])) $c->where(array($options['where']));
        $collection = $this->modx->getCollection($options['class'], $c);

        $this->modx->getCacheManager();

        // Loop over stuff to generate
        $pk = isset($options['primary']) ? $options['primary'] : '';
        foreach ($collection as $object) {
            /** @var xPDOObject $object */
            $file = $this->generate($object, $options);
            $path = empty($pk) ? $object->getPrimaryKey() : $object->get($pk);

            $ext = (isset($options['extension'])) ? $options['extension'] : '.yaml';
            $fn = $folder . DIRECTORY_SEPARATOR . $path . $ext;
            $after[] = $fn;

            if (!file_exists($fn) || file_get_contents($fn) != $file) {
                $this->modx->cacheManager->writeFile($fn, $file);
            }
        }

        // Clean up removed object files
        $old = array_diff($before, $after);
        foreach ($old as $oldFile)
        {
            unlink($oldFile);
        }
    }

    /**
     * @param xPDOObject|modElement $object
     * @param array $options
     * @return string
     */
    public function generate($object, array $options = array())
    {
        $fieldMeta = $object->_fieldMeta;
        $data = $object->toArray('', true, true);

        // If there's a dedicated content field, we put that below the yaml for easier managing
        $content = '';
        if (method_exists($object, 'getContent')) {
            $content = $object->getContent();

            if (!empty($content)) {
                foreach ($data as $key => $value) {
                    if ($value == $content) unset($data[$key]);
                }
            }
        }

        // Strip out keys that have the same value as the default, or are excluded per the .gitify
        $excludes = (isset($options['exclude_keys']) && is_array($options['exclude_keys'])) ? $options['exclude_keys'] : array();
        foreach ($data as $key => $value) {
            if (
                (isset($fieldMeta[$key]['default']) && $value === $fieldMeta[$key]['default']) //@fixme
                || in_array($key, $excludes)
            )
            {
                unset($data[$key]);
            }
        }

        // Handle string-based categories automagically on elements
        if ($object instanceof modElement && !($object instanceof modCategory)) {
            if (isset($data['category']) && !empty($data['category']) && is_numeric($data['category'])) {
                $catId = $data['category'];
                $data['category'] = $this->getCategoryName($catId);
            }
        }

        $data = $this->expandJSON($data);

        $out = Gitify::toYAML($data);

        if (!empty($content)) {
            $out .= Gitify::$contentSeparator . $content;
        }
        return $out;
    }

    /**
     * Loads a package (xPDO Model) by its name
     *
     * @param $package
     * @param array $options
     */
    public function getPackage($package, array $options = array())
    {
        $path = (isset($options['package_path'])) ? $options['package_path'] : false;
        if (!$path) {
            $path = $this->modx->getOption($package . '.core_path', null, $this->modx->getOption('core_path') . 'components/' . $package . '/', true);
            $path .= 'model/';
        }

        $this->modx->addPackage($package, $path);
    }

    /**
     * Loops over a folder to get all the files in it. Uses for cleaning up old files.
     *
     * @param $folder
     * @return array
     */
    public function getAllFiles($folder)
    {
        $files = array();
        try {
            $di = new RecursiveDirectoryIterator($folder, RecursiveDirectoryIterator::SKIP_DOTS);
            $it = new RecursiveIteratorIterator($di);
        } catch (Exception $e) {
            return array();
        }

        foreach($it as $file)
        {
            /** @var SplFileInfo $file */
            $files[] = $file->getPath() . DIRECTORY_SEPARATOR . $file->getFilename();
        }
        return $files;
    }


    /**
     * Turns a category ID into a name
     *
     * @param $id
     * @return string
     */
    public function getCategoryName($id) {
        if (isset($this->categories[$id])) return $this->categories[$id];

        $category = $this->modx->getObject('modCategory', $id);
        if ($category) {
            $this->categories[$id] = $category->get('category');
            return $this->categories[$id];
        }
        return '';
    }

    /**
     * Takes in an array of data, expanding JSON formatted data into an array to be nicely
     * transformed into YAML later.
     *
     * @param $data
     * @return mixed
     */
    public function expandJSON($data)
    {
        foreach ($data as $key => $value) {
            if (!empty($value) && is_string($value) && (strpos($value, '{') !== -1)) {
                $json = $this->modx->fromJSON($value);
                if (is_array($json)) $data[$key] = $this->expandJSON($json);
            }
            elseif (is_array($value)) {
                $data[$key] = $this->expandJSON($value);
            }
        }
        return $data;
    }
}
