<?php
/**
 * Handles filesystem-level tasks
 * 
 * @copyright  Copyright (c) 2008 William Bond
 * @author     William Bond [wb] <will@flourishlib.com>
 * @license    http://flourishlib.com/license
 * 
 * @package    Flourish
 * @link       http://flourishlib.com/fFilesystem
 * 
 * @version    1.0.0b
 * @changes    1.0.0b  The initial implementation [wb, 2008-03-24]
 */
class fFilesystem
{
	/**
	 * Stores the operations to perform when a commit occurs
	 * 
	 * @var array
	 */
	static private $commit_operations = NULL;
	
	/**
	 * Maps exceptions to all instances of a file or directory, providing consistency
	 * 
	 * @var array
	 */
	static private $exception_map = array();
	
	/**
	 * Stores file and directory names by reference, allowing all object instances to be updated at once
	 * 
	 * @var array
	 */
	static private $filename_map = array();
	
	/**
	 * Stores the operations to perform if a rollback occurs
	 * 
	 * @var array
	 */
	static private $rollback_operations = NULL;
	
	/**
	 * Stores a list of search => replace strings for web path translations
	 * 
	 * @var array
	 */
	static private $web_path_translations = array();
	
	
	/**
	 * Adds a directory to the web path translation list
	 * 
	 * The web path conversion list is a list of directory paths that will be
	 * converted (from the beginning of filesystem paths) when preparing a path
	 * for output into HTML.
	 * 
	 * By default the DOCUMENT_ROOT will be converted to a blank string, in
	 * essence stripping it from filesystem paths.
	 * 
	 * @param  string $search_path   The path to look for
	 * @param  string $replace_path  The path to replace with
	 * @return void
	 */
	static public function addWebPathTranslation($search_path, $replace_path)
	{
		// Ensure we have the correct kind of slash for the OS being used
		$search_path  = str_replace(array('/', '\\'), DIRECTORY_SEPARATOR, $search_path);
		$replace_path = str_replace(array('/', '\\'), DIRECTORY_SEPARATOR, $replace_path);
		self::$web_path_translations[$search_path] = $replace_path;
	}
	
	
	/**
	 * Starts a filesystem pseudo-transaction, should only be called when no transaction is in progress.
	 * 
	 * Flourish filesystem transactions are NOT full ACID-compliant
	 * transactions, but rather more of an filesystem undo buffer which can
	 * return the filesystem to the state when begin() was called. If your PHP
	 * script dies in the middle of an operation this functionality will do
	 * nothing for you and all operations will be retained, except for deletes
	 * which only occur once the transaction is committed.
	 * 
	 * @return void
	 */
	static public function begin()
	{
		if (self::$commit_operations !== NULL) {
			fCore::toss(
				'fProgrammerException',
				fGrammar::compose(
					'There is already a filesystem transaction in progress'
				)
			);
		}
		self::$commit_operations   = array();
		self::$rollback_operations = array();
	}
	
	
	/**
	 * Commits a filesystem transaction, should only be called when a transaction is in progress
	 * 
	 * @return void
	 */
	static public function commit()
	{
		if (!self::isInsideTransaction()) {
			fCore::toss(
				'fProgrammerException',
				fGrammar::compose(
					'There is no filesystem transaction in progress to commit'
				)
			);
		}
		
		$commit_operations = self::$commit_operations;
		
		self::$commit_operations   = NULL;
		self::$rollback_operations = NULL;
		
		$commit_operations = array_reverse($commit_operations);
		
		foreach ($commit_operations as $operation) {
			// Commit operations only include deletes, however it could be a filename or object
			if (isset($operation['filename'])) {
				@unlink($operation['filename']);
			} else {
				$operation['object']->delete();
			}
		}
	}
	
	
	/**
	 * Takes a file size and converts it to bytes
	 * 
	 * @param  string $size  The size to convert to bytes
	 * @return integer  The number of bytes represented by the size
	 */
	static public function convertToBytes($size)
	{
		if (!preg_match('#^(\d+)\s*(k|m|g|t)?(ilo|ega|era|iga)?( )?b?(yte(s)?)?$#', strtolower(trim($size)), $matches)) {
			fCore::toss(
				'fProgrammerException',
				fGrammar::compose(
					'The size specified, %s, does not appears to be a valid size',
					fCore::dump($size)
				)
			);
		}
		
		if ($matches[2] == '') {
			$matches[2] = 'b';
		}
		
		$size_map = array('b' => 1,
						  'k' => 1024,
						  'm' => 1048576,
						  'g' => 1073741824,
						  't' => 1099511627776);
		return $matches[1] * $size_map[$matches[2]];
	}
	
	
	/**
	 * Returns a unique name for a file
	 * 
	 * @param  string $file           The filename to check
	 * @param  string $new_extension  The new extension for the filename, do not include .
	 * @return string  The unique file name
	 */
	static public function createUniqueName($file, $new_extension=NULL)
	{
		$info = self::getPathInfo($file);
		
		// Change the file extension
		if ($new_extension !== NULL) {
			$new_extension = ($new_extension) ? '.' . $new_extension : $new_extension;
			$file = $info['dirname'] . $info['filename'] . $new_extension;
			$info = self::getPathInfo($file);
		}
		
		// If there is an extension, be sure to add . before it
		$extension = (!empty($info['extension'])) ? '.' . $info['extension'] : '';
		
		// Remove _copy# from the filename to start
		$file = preg_replace('#_copy(\d+)' . preg_quote($extension, '#') . '$#', $extension, $file);
		
		// Look for a unique name by adding _copy# to the end of the file
		while (file_exists($file)) {
			$info = self::getPathInfo($file);
			if (preg_match('#_copy(\d+)' . preg_quote($extension, '#') . '$#', $file, $match)) {
				$file = preg_replace('#_copy(\d+)' . preg_quote($extension, '#') . '$#', '_copy' . ($match[1]+1) . $extension, $file);
			} else {
				$file = $info['dirname'] . $info['filename'] . '_copy1' . $extension;
			}
		}
		
		return $file;
	}
	
	
	/**
	 * Takes the size of a file in bytes and returns a friendly size in b/kb/mb/gb/tb
	 * 
	 * @param  integer $bytes           The size of the file in bytes
	 * @param  integer $decimal_places  The number of decimal places to display
	 * @return string
	 */
	static public function formatFilesize($bytes, $decimal_places=1)
	{
		if ($bytes < 0) {
			$bytes = 0;
		}
		$suffixes  = array('b', 'kb', 'mb', 'gb', 'tb');
		$sizes     = array(1, 1024, 1048576, 1073741824, 1099511627776);
		$suffix    = floor(log($bytes)/6.9314718);
		return number_format($bytes/$sizes[$suffix], $decimal_places) . $suffixes[$suffix];
	}
	
	
	/**
	 * Returns info about a path including dirname, basename, extension and filename
	 * 
	 * @param  string $file_path  The file to rename
	 * @param  string $element    The piece of information to return ('dirname', 'basename', 'extension', or 'filename')
	 * @return array  The file's dirname, basename, extension and filename
	 */
	static public function getPathInfo($file, $element=NULL)
	{
		$valid_elements = array('dirname', 'basename', 'extension', 'filename');
		if ($element !== NULL && !in_array($element, $valid_elements)) {
			fCore::toss(
				'fProgrammerException',
				fGrammar::compose(
					'The element specified, %1$s, is invalid. Must be one of: %2$s.',
					fCore::dump($element),
					join(', ', $valid_elements)
				)
			);
		}
		
		$path_info = pathinfo($file);
		
		if (!isset($path_info['extension'])) {
			$path_info['extension'] = NULL;
		}
		
		if (!isset($path_info['filename'])) {
			$path_info['filename'] = preg_replace('#\.' . preg_quote($path_info['extension'], '#') . '$#', '', $path_info['basename']);
		}
		$path_info['dirname'] .= DIRECTORY_SEPARATOR;
		
		if ($element) {
			return $path_info[$element];
		}
		
		return $path_info;
	}
	
	
	/**
	 * Hooks a file/directory into the exception map entry for that filename
	 * 
	 * Since the value is returned by reference, all objects that represent
	 * this file/directory always see the same exception.
	 * 
	 * @internal
	 * 
	 * @param  string $file  The name of the file or directory
	 * @return mixed  Will return NULL if no match, or the exception object if a match occurs
	 */
	static public function &hookExceptionMap($file)
	{
		if (!isset(self::$exception_map[$file])) {
			self::$exception_map[$file] = NULL;
		}
		return self::$exception_map[$file];
	}
	
	
	/**
	 * Hooks a file/directory name to the filename map
	 * 
	 * Since the value is returned by reference, all objects that represent
	 * this file/directory will always be update on a rename.
	 * 
	 * @internal
	 * 
	 * @param  string $file  The name of the file or directory
	 * @return mixed  Will return NULL if no match, or the exception object if a match occurs
	 */
	static public function &hookFilenameMap($file)
	{
		if (!isset(self::$filename_map[$file])) {
			self::$filename_map[$file] = $file;
		}
		return self::$filename_map[$file];
	}
	
	
	/**
	 * Indicates if a transaction is in progress
	 * 
	 * @return void
	 */
	static public function isInsideTransaction()
	{
		return is_array(self::$commit_operations);
	}
	
	
	/**
	 * Saves an object to the identity map
	 * 
	 * @internal
	 * 
	 * @param  string    $file		 A file or directory name, directories should end in / or \
	 * @param  Exception $exception  The exception for this file/directory
	 * @return void
	 */
	static public function updateExceptionMap($file, Exception $exception)
	{
		self::$exception_map[$file] = $exception;
	}
	
	
	/**
	 * Updates the filename map, causing all objects representing a file/directory to be updated
	 * 
	 * @internal
	 * 
	 * @param  string $existing_filename  The existing filename
	 * @param  string $new_filename       The new filename
	 * @return void
	 */
	static public function updateFilenameMap($existing_filename, $new_filename)
	{
		if ($existing_filename == $new_filename) {
			return;
		}
		
		self::$filename_map[$new_filename]  =& self::$filename_map[$existing_filename];
		self::$exception_map[$new_filename] =& self::$exception_map[$existing_filename];
		
		unset(self::$filename_map[$existing_filename]);
		unset(self::$exception_map[$existing_filename]);
		
		self::$filename_map[$new_filename] = $new_filename;
	}
	
	
	/**
	 * Updates the filename map recursively, causing all objects representing a directory to be updated
	 * 
	 * Also updated all files and directories in the specified directory to the new paths.
	 * 
	 * @internal
	 * 
	 * @param  string $existing_dirname  The existing directory name
	 * @param  string $new_dirname       The new dirname
	 * @return void
	 */
	static public function updateFilenameMapForDirectory($existing_dirname, $new_dirname)
	{
		if ($existing_dirname == $new_dirname) {
			return;
		}
		
		// Handle the directory name
		self::$filename_map[$new_dirname]  =& self::$filename_map[$existing_dirname];
		self::$exception_map[$new_dirname] =& self::$exception_map[$existing_dirname];
		
		unset(self::$filename_map[$existing_dirname]);
		unset(self::$exception_map[$existing_dirname]);
		
		self::$filename_map[$new_dirname] = $new_dirname;
		
		// Handle all of the directories and files inside this directory
		foreach (self::$filename_map as $filename => $ignore) {
			if (preg_match('#^' . preg_quote($existing_dirname, '#') . '#', $filename)) {
				$new_filename = preg_replace('#^' . preg_quote($existing_dirname, '#') . '#', $new_dirname, $filename);
				
				self::$filename_map[$new_filename]  =& self::$filename_map[$filename];
				self::$exception_map[$new_filename] =& self::$exception_map[$filename];
				
				unset(self::$filename_map[$filename]);
				unset(self::$exception_map[$filename]);
				
				self::$filename_map[$new_filename] = $new_filename;
					
			}
		}
	}
	
	
	/**
	 * Keeps a record of created files so they can be deleted up in case of a rollback
	 * 
	 * @internal
	 * 
	 * @param  object $object  The new file or directory to get rid of on rollback
	 * @return void
	 */
	static public function recordCreate($object)
	{
		self::$rollback_operations[] = array(
			'action' => 'delete',
			'object' => $object
		);
	}
	
	
	/**
	 * Keeps track of file and directory names to delete when a transaction is committed
	 * 
	 * @internal
	 * 
	 * @param  fFile|fDirectory $object  The filesystem object to delete
	 * @return void
	 */
	static public function recordDelete($object)
	{
		self::$commit_operations[] = array(
			'action' => 'delete',
			'object' => $object
		);
	}
	
	
	/**
	 * Keeps a record of duplicated files so they can be cleaned up in case of a rollback
	 * 
	 * @internal
	 * 
	 * @param  fFile $file  The duplicate file to get rid of on rollback
	 * @return void
	 */
	static public function recordDuplicate(fFile $file)
	{
		self::$rollback_operations[] = array(
			'action'   => 'delete',
			'filename' => $file->getPath()
		);
	}
	
	
	/**
	 * Keeps a temp file in place of the old filename so the file can be restored during a rollback
	 * 
	 * @internal
	 * 
	 * @param  string $old_name  The old file or directory name
	 * @param  string $new_name  The new file or directory name
	 * @return void
	 */
	static public function recordRename($old_name, $new_name)
	{
		self::$rollback_operations[] = array(
			'action'   => 'rename',
			'old_name' => $old_name,
			'new_name' => $new_name
		);
		
		// Create the file with no content to prevent overwriting by another process
		file_put_contents($old_name, '');
		
		self::$commit_operations[] = array(
			'action'   => 'delete',
			'filename' => $old_name
		);
	}
	
	
	/**
	 * Keeps backup copies of files so they can be restored if there is a rollback
	 * 
	 * @internal
	 * 
	 * @param  fFile $file  The file that is being written to
	 * @return void
	 */
	static public function recordWrite(fFile $file)
	{
		self::$rollback_operations[] = array(
			'action'   => 'write',
			'filename' => $file->getPath(),
			'old_data' => file_get_contents($file->getPath())
		);
	}
	
	
	/**
	 * Rolls back a filesystem transaction, it is safe to rollback when no transaction is in progress
	 * 
	 * @return void
	 */
	static public function rollback()
	{
		self::$rollback_operations = array_reverse(self::$rollback_operations);
		
		foreach (self::$rollback_operations as $operation) {
			switch($operation['action']) {
				
				case 'delete':
					@unlink($operation['filename']);
					break;
					
				case 'write':
					file_put_contents($operation['filename'], $operation['old_data']);
					break;
					
				case 'rename':
					@rename($operation['new_name'], $operation['old_name']);
					break;
					
			}
		}
		
		// All files to be deleted should have their exceptions erased
		foreach (self::$commit_operations as $operation) {
			if (isset($operation['object'])) {
				self::updateExceptionMap($operation['object']->getPath(), NULL);
			}
		}
		
		self::$commit_operations   = NULL;
		self::$rollback_operations = NULL;
	}
	
	
	/**
	 * Takes a filesystem path and translates it to a web path using the rules added
	 * 
	 * @param  string $path  The path to translate
	 * @return string  The filesystem path translated to a web path
	 */
	static public function translateToWebPath($path)
	{
		$translations = array(realpath($_SERVER['DOCUMENT_ROOT']) => '') + self::$web_path_translations;
		
		foreach ($translations as $search => $replace) {
			$path = preg_replace('#^' . preg_quote($search, '#') . '#', $replace, $path);
		}
		
		return $path;
	}
	
	
	/**
	 * Forces use as a static class
	 * 
	 * @return fFilesystem
	 */
	private function __construct() { }
}



/**
 * Copyright (c) 2008 William Bond <will@flourishlib.com>
 * 
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 * 
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 * 
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */