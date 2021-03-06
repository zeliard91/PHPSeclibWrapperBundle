<?php

namespace Dedipanel\PHPSeclibWrapperBundle\SFTP;

use Dedipanel\PHPSeclibWrapperBundle\SFTP\Exception\UnreachableItemException;

class Directory extends AbstractItem implements \Iterator, \Countable
{
    /** @var array $content **/
    protected $content;
    /** @var integer $pos **/
    private $pos = 0;


    public function setContent(array $content = array())
    {
        $this->content = $content;

        return $this;
    }

    /**
     * @{inheritdoc}
     */
    public function rewind()
    {
        $this->pos = 0;
        
        return $this;
    }

    /**
     * @{inheritdoc}
     */
    public function current()
    {
        return $this->content[$this->pos];
    }

    /**
     * @{inheritdoc}
     */
    public function key()
    {
        return $this->pos;
    }

    /**
     * @{inheritdoc}
     */
    public function next()
    {
        ++$this->pos;
        
        return $this;
    }

    /**
     * @{inheritdoc}
     */
    public function valid()
    {
        return isset($this->content[$this->pos]);
    }

    /**
     * @{inheritdoc}
     */
    public function count()
    {
        return count($this->content);
    }

    /**
     * @{inheritdoc}
     */
    public function retrieve()
    {
        $path = $this->getRelativePath();
        $fullPath = $this->getFullPath();

        $content = $this->conn->getSFTP()->rawlist($fullPath);

        $this->conn->getLogger()->debug(get_class($this) . '::retrieve', array('phpseclib_logs' => $this->conn->getSFTP()->getSFTPLog()));
        $this->conn->getLogger()->info(get_class($this) . '::retrieve - Retrieving directory "{path}" on sftp server "{server}" {ret}.', array(
            'path' => $fullPath,
            'server' => strval($this->conn->getServer()),
            'ret' => ($content != false) ? 'succeed' : 'failed',
        ));

        if ($content === false) {
            throw new UnreachableItemException($this);
        }

        $dirs  = array();
        $files = array();

        foreach ($content AS $name => $item) {
            if ('..' == $name && empty($path)) continue;
            elseif ('.' == $name) continue;

            if ($item['type'] == 1) {
                $resource = new File($this->conn, $path . '/' . $name, $this->chrootDir);
                $files[$name] = $resource;

                $resource->setSize($item['size']);
            }
            else {
                $resource = new Directory($this->conn, $path . '/' . $name, $this->chrootDir);
                $dirs[$name] = $resource;
            }

            $resource->setMtime($item['mtime']);
        }

        ksort($dirs);
        ksort($files);

        $this->content = array_merge($dirs, $files);
        $this->retrieved = true;

        return $content;
    }

    /**
     * @{inheritdoc}
     */
    public function create()
    {
        $path = $this->getFullPath();

        $created = $this->conn->getSFTP()->mkdir($path);

        $this->conn->getLogger()->debug(get_class($this) . '::create', array('phpseclib_logs' => $this->conn->getSFTP()->getSFTPLog()));
        $this->conn->getLogger()->info(get_class($this) . '::create - Creating directory "{path}" on sftp server "{server}" {ret}', array(
            'path' => $path,
            'server' => $this->conn->getServer(),
            'ret' => ($created != false) ? 'succeed' : 'failed',
        ));

        if ($created == false) {
            throw new UnreachableItemException($this);
        }

        return $created;
    }

    /**
     * @{inheritdoc}
     */
    public function update()
    {
        $this->conn->getLogger()->info(get_class($this) . '::update - Updating directory "{path}" on sftp server "{server}".', array(
            'path' => $this->getFullPath(),
            'server' => $this->conn->getServer(),
        ));

        return $this->rename();
    }
}
