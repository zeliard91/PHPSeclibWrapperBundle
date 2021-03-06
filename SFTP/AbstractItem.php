<?php

namespace Dedipanel\PHPSeclibWrapperBundle\SFTP;

use Dedipanel\PHPSeclibWrapperBundle\Connection\ConnectionInterface;
use Dedipanel\PHPSeclibWrapperBundle\SFTP\Exception\InvalidPathException;
use Dedipanel\PHPSeclibWrapperBundle\SFTP\Exception\UnreachableItemException;

abstract class AbstractItem
{
    /** @var ConnectionInterface $conn */
    protected $conn;
    /** @var string $name */
    protected $name;
    /** @var mixed $content */
    protected $content;
    /** @var string $path */
    protected $path;
    /** @var string $mtime */
    protected $mtime;
    /** @var string $chrootDir */
    protected $chrootDir;
    /** @var boolean $retrieved */
    protected $retrieved;
    /** @var boolean $new */
    protected $new;
    /** @var string $oldPath */
    protected $oldPath;
    /** @var string $oldName */
    protected $oldName;

    /**
     * @param ConnectionInterface $conn
     * @param $path
     * @param $name
     * @param null $chrootDir The constructor will automatically chroot
     *                        to the user home if no parameter is passed
     */
    public function __construct(ConnectionInterface $conn, $pathname, $chrootDir = null, $new = false)
    {
        $this->conn = $conn;

        if (empty($chrootDir)) {
            $chrootDir = $this->conn->getServer()->getHome();
        }

        $this->chrootDir = rtrim($chrootDir, '/');

        $pathinfo = pathinfo($pathname);

        if ($pathinfo['dirname'] == '.' && $pathinfo['basename'] == '~') {
            $pathinfo['dirname']  = '~/';
            $pathinfo['basename'] = '';
            $pathinfo['filename'] = '';
        }
        elseif ($pathinfo['dirname'] == '~') {
            $pathinfo['dirname'] = '~/';
        }

        if ($new) {
            $pathinfo['dirname'] .= '/' . $pathinfo['basename'];
            $pathinfo['basename'] = '';
        }

        $this->setName($pathinfo['basename'], false);
        $this->setPath($pathinfo['dirname'], !$new);

        $this->new = $new;
        $this->content = null;
    }

    /**
     * Get the item name
     *
     * @param $name
     * @return Abstractitem
     */
    public function setName($name, $validate = true)
    {
        $this->name = $name;

        if (empty($this->oldName)) {
            $this->oldName = $name;
        }

        if ($validate && !$this->validatePath()) {
            throw new InvalidPathException($this->name);
        }

        return $this;
    }
    
    /**
     * Get directory name
     * 
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Get the current item content
     *
     * @return mixed
     */
    public function getContent()
    {
        if (!$this->retrieved && !$this->new) {
            $this->retrieve();
        }

        return $this->content;
    }

    /**
     * Set the current item path
     * (relative to the chrootDir)
     *
     * @param $path
     * @param boolean $validate
     * @return Abstractitem
     * @throws Exception\InvalidPathException
     */
    public function setPath($path, $validate = true)
    {
        if (strpos($path, $this->chrootDir) === 0) {
            $path = substr_replace($path, '', 0, strlen($this->chrootDir));
        }
        elseif (substr($path, 0, 2) == '~/') {
            $path = substr($path, 2);
        }

        $this->path = trim($path, '/');

        if ($validate && !$this->validatePath()) {
            throw new InvalidPathException($this->path);
        }

        if (empty($this->oldPath)) {
            $this->oldPath = $this->path;
        }

        return $this;
    }
    
    /**
     * Get the directory path
     * 
     * @return string
     */
    public function getPath()
    {
        return $this->path;
    }

    /**
     * Validate the full path of the item
     *
     * @return bool
     */
    public function validatePath()
    {
        $path = $this->getFullPath();

        return
            strpos($path, $this->chrootDir) === 0
            && (strpos($path, '..') === false || !empty($this->path));
    }

    public function setMtime($mtime)
    {
        $this->mtime = $mtime;

        return $this;
    }

    public function getMtime()
    {
        return $this->mtime;
    }

    /**
     * Get the dir path
     *
     * @return string
     */
    public function getChrootDir()
    {
        return $this->chrootDir;
    }

    /**
     * Is the item content already retrieved ?
     *
     * @return bool
     */
    public function isRetrieved()
    {
        return $this->retrieved;
    }

    /**
     * Set if the current item is new
     *
     * @param bool $new
     * @return $this
     */
    public function setNew($new = true)
    {
        $this->new = $new;

        return $this;
    }

    /**
     * Is the current item new ?
     *
     * @return bool
     */
    public function isNew()
    {
        return $this->new;
    }
    
    /**
     * Get the item full path (chroot dir + relative path + name)
     *
     * @param $itemPath string|null Can provide a string for resolving it
     * @param $name string|null Can provide a string for resolving it
     * @return string
     */
    public function getFullPath($itemPath = null, $name = null)
    {

        return $this->chrootDir . '/' . $this->getRelativePath($itemPath, $name);
    }

    /**
     * Get the item path relative to the chrootDir
     *
     * @param string|null $itemPath
     * @param string|null $name
     * @return string
     */
    public function getRelativePath($itemPath = null, $name = null)
    {
        $path = '';

        if (!is_null($itemPath)) {
            $path .= $itemPath . '/';
        }
        elseif (!empty($this->path)) {
            $path .= $this->path . '/';
        }

        if (!is_null($name)) {
            return $path . $name;
        }

        return rtrim($path . $this->name, '/');
    }

    /**
     * Retrieve the item content
     *
     * @return mixed
     */
    abstract public function retrieve();

    /**
     * Create the item
     *
     * @return boolean
     */
    abstract public function create();

    /**
     * Update the item
     *
     * @return boolean
     */
    abstract public function update();

    /**
     * Delete the item from server
     *
     * @return boolean
     * @throws UnreachableItemException
     */
    public function delete()
    {
        $path = $this->getFullPath();

        $removed = $this->conn->getSFTP()->delete($path);

        $this->conn->getLogger()->debug(get_class($this) . '::remove', array('phpseclib_logs' => $this->conn->getSFTP()->getSFTPLog()));
        $this->conn->getLogger()->info(get_class($this) . '::remove - Removing "{path}" on sftp server "{server}" {ret}', array(
            'path' => $path,
            'server' => $this->conn->getServer(),
            'ret' => ($removed != false) ? 'succeed' : 'failed',
        ));

        if ($removed == false) {
            throw new UnreachableItemException($this);
        }

        return $removed;
    }

    /**
     * Rename item from its old path to its new
     *
     * @return boolean
     * @throws UnreachableItemException
     */
    public function rename()
    {
        $oldPath = $this->getFullPath($this->oldPath, $this->oldName);
        $newPath = $this->getFullPath();

        $renamed = $this->conn->getSFTP()->rename($oldPath, $newPath);

        $this->conn->getLogger()->debug(get_class($this) . '::rename', array('phpseclib_logs' => $this->conn->getSFTP()->getSFTPLog()));
        $this->conn->getLogger()->info(get_class($this) . '::rename - Renaming "{old_path}" to "{path}" on sftp server "{server}" {ret}', array(
            'old_path' => $oldPath,
            'path' => $newPath,
            'server' => $this->conn->getServer(),
            'ret' => ($renamed != false) ? 'succeed' : 'failed',
        ));

        if ($renamed == false) {
            throw new UnreachableItemException($this);
        }

        return $renamed;
    }
}
