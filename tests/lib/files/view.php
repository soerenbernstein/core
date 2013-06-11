<?php
/**
 * Copyright (c) 2012 Robin Appelman <icewind@owncloud.com>
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 * See the COPYING-README file. */

namespace Test\Files;

class TemporaryNoTouch extends \OC\Files\Storage\Temporary {
	public function touch($path, $mtime = null) {
		return false;
	}
}

class View extends \PHPUnit_Framework_TestCase {
	/**
	 * @var \OC\Files\Storage\Storage[] $storages;
	 */
	private $storages = array();

	public function setUp() {
		\OC\Files\Filesystem::clearMounts();
	}

	public function tearDown() {
		foreach ($this->storages as $storage) {
			$cache = $storage->getCache();
			$ids = $cache->getAll();
			$permissionsCache = $storage->getPermissionsCache();
			$permissionsCache->removeMultiple($ids, \OC_User::getUser());
			$cache->clear();
		}
	}

	/**
	 * @medium
	 */
	public function testCacheAPI() {
		$storage1 = $this->getTestStorage();
		$storage2 = $this->getTestStorage();
		$storage3 = $this->getTestStorage();
		\OC\Files\Filesystem::mount($storage1, array(), '/');
		\OC\Files\Filesystem::mount($storage2, array(), '/substorage');
		\OC\Files\Filesystem::mount($storage3, array(), '/folder/anotherstorage');
		$textSize = strlen("dummy file data\n");
		$imageSize = filesize(\OC::$SERVERROOT . '/core/img/logo.png');
		$storageSize = $textSize * 2 + $imageSize;

		$rootView = new \OC\Files\View('');

		$cachedData = $rootView->getFileInfo('/foo.txt');
		$this->assertEquals($textSize, $cachedData['size']);
		$this->assertEquals('text/plain', $cachedData['mimetype']);
		$this->assertNotEquals(-1, $cachedData['permissions']);

		$cachedData = $rootView->getFileInfo('/');
		$this->assertEquals($storageSize * 3, $cachedData['size']);
		$this->assertEquals('httpd/unix-directory', $cachedData['mimetype']);

		$cachedData = $rootView->getFileInfo('/folder');
		$this->assertEquals($storageSize + $textSize, $cachedData['size']);
		$this->assertEquals('httpd/unix-directory', $cachedData['mimetype']);

		$folderData = $rootView->getDirectoryContent('/');
		/**
		 * expected entries:
		 * folder
		 * foo.png
		 * foo.txt
		 * substorage
		 */
		$this->assertEquals(4, count($folderData));
		$this->assertEquals('folder', $folderData[0]['name']);
		$this->assertEquals('foo.png', $folderData[1]['name']);
		$this->assertEquals('foo.txt', $folderData[2]['name']);
		$this->assertEquals('substorage', $folderData[3]['name']);

		$this->assertEquals($storageSize + $textSize, $folderData[0]['size']);
		$this->assertEquals($imageSize, $folderData[1]['size']);
		$this->assertEquals($textSize, $folderData[2]['size']);
		$this->assertEquals($storageSize, $folderData[3]['size']);

		$folderData = $rootView->getDirectoryContent('/substorage');
		/**
		 * expected entries:
		 * folder
		 * foo.png
		 * foo.txt
		 */
		$this->assertEquals(3, count($folderData));
		$this->assertEquals('folder', $folderData[0]['name']);
		$this->assertEquals('foo.png', $folderData[1]['name']);
		$this->assertEquals('foo.txt', $folderData[2]['name']);

		$folderView = new \OC\Files\View('/folder');
		$this->assertEquals($rootView->getFileInfo('/folder'), $folderView->getFileInfo('/'));

		$cachedData = $rootView->getFileInfo('/foo.txt');
		$this->assertFalse($cachedData['encrypted']);
		$id = $rootView->putFileInfo('/foo.txt', array('encrypted' => true));
		$cachedData = $rootView->getFileInfo('/foo.txt');
		$this->assertTrue($cachedData['encrypted']);
		$this->assertEquals($cachedData['fileid'], $id);

		$this->assertFalse($rootView->getFileInfo('/non/existing'));
		$this->assertEquals(array(), $rootView->getDirectoryContent('/non/existing'));
	}

	/**
	 * @medium
	 */
	function testGetPath() {
		$storage1 = $this->getTestStorage();
		$storage2 = $this->getTestStorage();
		$storage3 = $this->getTestStorage();
		\OC\Files\Filesystem::mount($storage1, array(), '/');
		\OC\Files\Filesystem::mount($storage2, array(), '/substorage');
		\OC\Files\Filesystem::mount($storage3, array(), '/folder/anotherstorage');

		$rootView = new \OC\Files\View('');

		$cachedData = $rootView->getFileInfo('/foo.txt');
		$id1 = $cachedData['fileid'];
		$this->assertEquals('/foo.txt', $rootView->getPath($id1));

		$cachedData = $rootView->getFileInfo('/substorage/foo.txt');
		$id2 = $cachedData['fileid'];
		$this->assertEquals('/substorage/foo.txt', $rootView->getPath($id2));

		$folderView = new \OC\Files\View('/substorage');
		$this->assertEquals('/foo.txt', $folderView->getPath($id2));
		$this->assertNull($folderView->getPath($id1));
	}

	/**
	 * @medium
	 */
	function testMountPointOverwrite() {
		$storage1 = $this->getTestStorage(false);
		$storage2 = $this->getTestStorage();
		$storage1->mkdir('substorage');
		\OC\Files\Filesystem::mount($storage1, array(), '/');
		\OC\Files\Filesystem::mount($storage2, array(), '/substorage');

		$rootView = new \OC\Files\View('');
		$folderContent = $rootView->getDirectoryContent('/');
		$this->assertEquals(4, count($folderContent));
	}

	function testCacheIncompleteFolder() {
		$storage1 = $this->getTestStorage(false);
		\OC\Files\Filesystem::mount($storage1, array(), '/');
		$rootView = new \OC\Files\View('');

		$entries = $rootView->getDirectoryContent('/');
		$this->assertEquals(3, count($entries));

		// /folder will already be in the cache but not scanned
		$entries = $rootView->getDirectoryContent('/folder');
		$this->assertEquals(1, count($entries));
	}

	public function testAutoScan() {
		$storage1 = $this->getTestStorage(false);
		$storage2 = $this->getTestStorage(false);
		\OC\Files\Filesystem::mount($storage1, array(), '/');
		\OC\Files\Filesystem::mount($storage2, array(), '/substorage');
		$textSize = strlen("dummy file data\n");

		$rootView = new \OC\Files\View('');

		$cachedData = $rootView->getFileInfo('/');
		$this->assertEquals('httpd/unix-directory', $cachedData['mimetype']);
		$this->assertEquals(-1, $cachedData['size']);

		$folderData = $rootView->getDirectoryContent('/substorage/folder');
		$this->assertEquals('text/plain', $folderData[0]['mimetype']);
		$this->assertEquals($textSize, $folderData[0]['size']);
	}

	/**
	 * @medium
	 */
	function testSearch() {
		$storage1 = $this->getTestStorage();
		$storage2 = $this->getTestStorage();
		$storage3 = $this->getTestStorage();
		\OC\Files\Filesystem::mount($storage1, array(), '/');
		\OC\Files\Filesystem::mount($storage2, array(), '/substorage');
		\OC\Files\Filesystem::mount($storage3, array(), '/folder/anotherstorage');

		$rootView = new \OC\Files\View('');

		$results = $rootView->search('foo');
		$this->assertEquals(6, count($results));
		$paths = array();
		foreach ($results as $result) {
			$this->assertEquals($result['path'], \OC\Files\Filesystem::normalizePath($result['path']));
			$paths[] = $result['path'];
		}
		$this->assertContains('/foo.txt', $paths);
		$this->assertContains('/foo.png', $paths);
		$this->assertContains('/substorage/foo.txt', $paths);
		$this->assertContains('/substorage/foo.png', $paths);
		$this->assertContains('/folder/anotherstorage/foo.txt', $paths);
		$this->assertContains('/folder/anotherstorage/foo.png', $paths);

		$folderView = new \OC\Files\View('/folder');
		$results = $folderView->search('bar');
		$this->assertEquals(2, count($results));
		$paths = array();
		foreach ($results as $result) {
			$paths[] = $result['path'];
		}
		$this->assertContains('/anotherstorage/folder/bar.txt', $paths);
		$this->assertContains('/bar.txt', $paths);

		$results = $folderView->search('foo');
		$this->assertEquals(2, count($results));
		$paths = array();
		foreach ($results as $result) {
			$paths[] = $result['path'];
		}
		$this->assertContains('/anotherstorage/foo.txt', $paths);
		$this->assertContains('/anotherstorage/foo.png', $paths);

		$this->assertEquals(6, count($rootView->searchByMime('text')));
		$this->assertEquals(3, count($folderView->searchByMime('text')));
	}

	/**
	 * @medium
	 */
	function testWatcher() {
		$storage1 = $this->getTestStorage();
		\OC\Files\Filesystem::mount($storage1, array(), '/');

		$rootView = new \OC\Files\View('');

		$cachedData = $rootView->getFileInfo('foo.txt');
		$this->assertEquals(16, $cachedData['size']);

		$rootView->putFileInfo('foo.txt', array('storage_mtime' => 10));
		$storage1->file_put_contents('foo.txt', 'foo');
		clearstatcache();

		$cachedData = $rootView->getFileInfo('foo.txt');
		$this->assertEquals(3, $cachedData['size']);
	}

	/**
	 * @medium
	 */
	function testCopyBetweenStorages() {
		$storage1 = $this->getTestStorage();
		$storage2 = $this->getTestStorage();
		\OC\Files\Filesystem::mount($storage1, array(), '/');
		\OC\Files\Filesystem::mount($storage2, array(), '/substorage');

		$rootView = new \OC\Files\View('');
		$rootView->mkdir('substorage/emptyfolder');
		$rootView->copy('substorage', 'anotherfolder');
		$this->assertTrue($rootView->is_dir('/anotherfolder'));
		$this->assertTrue($rootView->is_dir('/substorage'));
		$this->assertTrue($rootView->is_dir('/anotherfolder/emptyfolder'));
		$this->assertTrue($rootView->is_dir('/substorage/emptyfolder'));
		$this->assertTrue($rootView->file_exists('/anotherfolder/foo.txt'));
		$this->assertTrue($rootView->file_exists('/anotherfolder/foo.png'));
		$this->assertTrue($rootView->file_exists('/anotherfolder/folder/bar.txt'));
		$this->assertTrue($rootView->file_exists('/substorage/foo.txt'));
		$this->assertTrue($rootView->file_exists('/substorage/foo.png'));
		$this->assertTrue($rootView->file_exists('/substorage/folder/bar.txt'));
	}

	/**
	 * @medium
	 */
	function testMoveBetweenStorages() {
		$storage1 = $this->getTestStorage();
		$storage2 = $this->getTestStorage();
		\OC\Files\Filesystem::mount($storage1, array(), '/');
		\OC\Files\Filesystem::mount($storage2, array(), '/substorage');

		$rootView = new \OC\Files\View('');
		$rootView->rename('foo.txt', 'substorage/folder/foo.txt');
		$this->assertFalse($rootView->file_exists('foo.txt'));
		$this->assertTrue($rootView->file_exists('substorage/folder/foo.txt'));
		$rootView->rename('substorage/folder', 'anotherfolder');
		$this->assertFalse($rootView->is_dir('substorage/folder'));
		$this->assertTrue($rootView->file_exists('anotherfolder/foo.txt'));
		$this->assertTrue($rootView->file_exists('anotherfolder/bar.txt'));
	}

	/**
	 * @medium
	 */
	function testTouch() {
		$storage = $this->getTestStorage(true, '\Test\Files\TemporaryNoTouch');

		\OC\Files\Filesystem::mount($storage, array(), '/');

		$rootView = new \OC\Files\View('');
		$oldCachedData = $rootView->getFileInfo('foo.txt');

		$rootView->touch('foo.txt', 500);

		$cachedData = $rootView->getFileInfo('foo.txt');
		$this->assertEquals(500, $cachedData['mtime']);
		$this->assertEquals($oldCachedData['storage_mtime'], $cachedData['storage_mtime']);

		$rootView->putFileInfo('foo.txt', array('storage_mtime' => 1000)); //make sure the watcher detects the change
		$rootView->file_put_contents('foo.txt', 'asd');
		$cachedData = $rootView->getFileInfo('foo.txt');
		$this->assertGreaterThanOrEqual($cachedData['mtime'], $oldCachedData['mtime']);
		$this->assertEquals($cachedData['storage_mtime'], $cachedData['mtime']);
	}

	/**
	 * @param bool $scan
	 * @return \OC\Files\Storage\Storage
	 */
	private function getTestStorage($scan = true, $class = '\OC\Files\Storage\Temporary') {
		/**
		 * @var \OC\Files\Storage\Storage $storage
		 */
		$storage = new $class(array());
		$textData = "dummy file data\n";
		$imgData = file_get_contents(\OC::$SERVERROOT . '/core/img/logo.png');
		$storage->mkdir('folder');
		$storage->file_put_contents('foo.txt', $textData);
		$storage->file_put_contents('foo.png', $imgData);
		$storage->file_put_contents('folder/bar.txt', $textData);

		if ($scan) {
			$scanner = $storage->getScanner();
			$scanner->scan('');
		}
		$this->storages[] = $storage;
		return $storage;
	}
}
