<?php
/**
 * Represents a git repository for interaction with git.
 *
 * @author Brent Goldthwaite <brent@carr-labs.com>
 * @license http://http://opensource.org/licenses/bsd-license.php BSD-2-Clause
 * @copyright (c) 2013, Brent Goldthwaite
 */

class GitRepository
{
	/**
	 * The path to the git executable.
	 *
	 * @var string
	 */
	public $gitPath = 'git';

	/**
	 * The default remote name
	 *
	 * @var string
	 */
	public $defaultRemote = 'origin';

	/**
	 * The path to the git repository
	 *
	 * @var string
	 */
	protected $_path;

	/**
	 * Holds the active branch
	 *
	 * @var string
	 */
	protected $_activeBranch;

	/**
	 * Holds an array of git branches
	 *
	 * @var GitBranch[]
	 */
	protected $_branches;

	/**
	 * Holds the tags in this branch
	 *
	 * @var GitTag[]
	 */
	protected $_tags;

	/**
	 * Holds an array of git remote repositories
	 *
	 * @var GitRemote[]
	 */
	protected $_remotes;

	/**
	 * Holds an array of git commits in this repository
	 *
	 * @var GitCommit[]
	 */
	protected $_commits = array();

	/**
	 * Constructor.
	 *
	 * @param string $path the path to the repository folder
	 * @param boolean $createIfEmpty whether to create the repository folder if it doesn't exist
	 * @param boolean $initialize whether to initialize git when creating a new repository
	 */
	public function __construct($path = NULL, $createIfEmpty = FALSE, $initialize = FALSE)
	{
		$this->setPath($path, $createIfEmpty, $initialize);
	}

	/**
	 * Sets the path to the git repository folder.
	 *
	 * @param string $path the path to the repository folder
	 * @param boolean $createIfEmpty whether to create the repository folder if it doesn't exist
	 * @param boolean $initialize whether to initialize git when creating a new repository
	 */
	public function setPath($path, $createIfEmpty = FALSE, $initialize = FALSE)
	{
		if(!($realPath = realpath($path)))
		{
			if(!$createIfEmpty)
			{
				throw new \InvalidArgumentException('The specified path does not exist: ' . $path);
			}
			mkdir($path);
			$realPath = realpath($path);
		}
		$this->_path = $realPath;
		if(!file_exists($realPath . '/.git'))
		{
			if($initialize)
			{
				$this->initialize();
			}
			else
			{
				throw new \InvalidArgumentException('The specified path is not a git repository');
			}
		}
	}

	/**
	 * Gets the path to the git repository folder
	 *
	 * @return string the path to the git repository folder
	 */
	public function getPath()
	{
		return $this->_path;
	}

	/**
	 * Initializes git
	 *
	 * @return string the response from git
	 */
	public function initialize()
	{
		return $this->run('init');
	}

	/**
	 * Runs a git command and returns the response
	 *
	 * @throws GitException if git returns an error
	 * @param string $command the git command to run
	 * @return string the response from git
	 */
	public function run($command)
	{
		$descriptor = array(
			1 => array('pipe', 'w'),
			2 => array('pipe', 'w'),
		);
		$pipes = array();
		$resource = proc_open($this->gitPath . ' ' . $command, $descriptor, $pipes, $this->getPath());
		$stdout = stream_get_contents($pipes[1]);
		$stderr = stream_get_contents($pipes[2]);
		foreach($pipes as $pipe)
		{
			fclose($pipe);
		}
		if(trim(proc_close($resource)) && $stderr)
		{
			throw new GitException($stderr);
		}
		return trim($stdout);
	}

	/**
	 * Adds a file or array of files to the git repository
	 *
	 * @throws GitException if there was an error adding the file
	 * @param string|array $file the file or files to add, pass an array to add multiple files
	 */
	public function add($file)
	{
		if(is_array($file))
		{
			foreach(array_values($file) as $file)
			{
				$this->add($file);
			}
			return;
		}
		if(!file_exists($file) && !(substr($file, 0, 1) != '/' && file_exists($this->getPath() . '/' . $file) ))
		{
			throw new GitException('Cannot add ' . $file . ' to the repository because it doesn\'t exist');
		}
		$this->run('add ' . $file);
	}

	/**
	 * Removes a file or array of files from the git repository
	 *
	 * @throws GitException if there was an error removing the file
	 * @param string|array $file the file or files to remove, pass an array to remove multiple files
	 * @param boolean $force whether to force removal of the file, even if there are staged changes
	 */
	public function rm($file, $force = FALSE)
	{
		if(is_array($file))
		{
			foreach(array_values($file) as $file)
			{
				$this->rm($file);
			}
			return;
		}
		if(!file_exists($file) && !(substr($file, 0, 1) != '/' && file_exists($this->getPath() . '/' . $file) ))
		{
			throw new GitException('Cannot remove ' . $file . ' from the repository because it doesn\'t exist');
		}
		if($force)
		{
			$this->run('rm -f ' . $file);
		}
		else
		{
			$this->run('rm ' . $file);
		}
	}

	/**
	 * Makes a git commit
	 *
	 * @param string $message the commit message
	 * @param boolean $addFiles whether to add changes from all known files
	 * @param boolean $amend whether the commit
	 * @return array|false an array of committed files, file => status, or false if the commit failed
	 */
	public function commit($message = NULL, $addFiles = FALSE, $amend = FALSE, $author = FALSE)
	{
		$command = 'commit ';
		if($addFiles)
		{
			$command .= '-a ';
		}
		if($amend)
		{
			$command .= '--amend ';
		}
		if($message)
		{
			$command .= ' -m "' . $message . '" ';
		}
		if($author)
		{
			$command .= ' --author="'.$author.'" ';
		}

		$result = $this->run($command . ' --porcelain');
		if(!$result)
		{
			return FALSE;
		}
		$this->run($command);
		$files = array();
		foreach(explode("\n", $result) as $line)
		{
			$status = trim(substr($line, 0, 3));
			$file = trim(substr($line, 3));
			$files[$file] = $status;
		}
		$this->_branches = NULL;
		return $files;
	}

	/**
	 * Gets an array of paths that have differences between the index file and the current HEAD commit
	 *
	 * @return array the differences, filename => status
	 */
	public function status()
	{
		$files = array();

		$output = $this->run('status --porcelain');
		if(empty($output))
		{
			return $files;
		}

		foreach(explode('\n', $output) as $n => $line)
		{
			list($status, $file) = explode(' ', trim($line), 2);
			$status = trim($status);
			$file = trim($file);
			if($file != '')
			{
				$files[$file] = $status;
			}
		}
		return $files;
	}

	/**
	 * Describes the state of the current repository (returns closest tag to current HEAD)
	 *
	 * @param string $options specify "--tags" to fetch all tags (defaults to annotated tags only).
	 * @see git help describe for more options and exact return values.
	 * @return string the response from git
	 */
	public function describe($options = '')
	{
		return $this->run('describe ' . $options);
	}

	/**
	 * Switches to the given branch
	 *
	 * @param string $branchName the name of the branch to check out
	 * @param boolean $create whether to create the branch or not
	 * @param boolean $force whether to force the checkout or not
	 * @return string the response of the checkout
	 */
	public function checkout($branchName, $create = FALSE, $force = FALSE)
	{
		$command = 'checkout ';
		if($create)
		{
			$command .= '-b ';
		}
		if($force)
		{
			$command .= '-f ';
		}
		$command .= $branchName;
		$this->_branches = NULL;
		return $this->run($command);
	}

	/**
	 * Cleans the working tree by recursively removing files that are not under version control.
	 *
	 * @param boolean $deleteDirectories whether to delete directories or not
	 * @param boolean $force whether to force the git to perform a clean.
	 * @return string the response from git
	 */
	public function clean($deleteDirectories = FALSE, $force = FALSE)
	{
		$command = 'clean';
		if($deleteDirectories)
		{
			$command .= ' -d';
		}
		if($force)
		{
			$command .= ' -f';
		}
		return $this->run($command);
	}

	/**
	 * Clones the current repository into a different directory
	 *
	 * @param string $targetDirectory the directory to clone into
	 * @return string the response from git
	 */
	public function cloneTo($targetDirectory)
	{
		$command = 'clone --local ' . $this->getPath() . ' ' . $targetDirectory;
		return $this->run($command);
	}

	/**
	 * Clones a different repository into the current repository
	 *
	 * @param string $sourceDirectory the directory to clone from
	 * @return string the response from git
	 */
	public function cloneFrom($targetDirectory)
	{
		$command = 'clone --local ' . $targetDirectory . ' ' . $this->getPath();
		return $this->run($command);
	}

	/**
	 * Clones a remote repository into the current repository
	 *
	 * @param string $sourceUrl the remote repository url
	 * @return string the response from git
	 */
	public function cloneRemote($sourceUrl)
	{
		$command = 'clone ' . $sourceUrl . ' ' . $this->getPath();
		return $this->run($command);
	}

	/**
	 * Pushes a branch to a remote repository.
	 *
	 * @param string|GitRemote $remote the remote repository to push to
	 * @param string|GitBranch $branch the git branch to push, defaults to "master"
	 * @param boolean $force whether to force the push or not, defaults to false
	 * @return string the response from git
	 */
	public function push($remote, $branch = 'master', $force = FALSE)
	{
		if($remote instanceof GitRemote)
		{
			$remote = $remote->name;
		}
		if($branch instanceof GitBranch)
		{
			$branch = $branch->name;
		}
		if($force)
		{
			$command = 'push -f ' . $remote . ' ' . $branch;
		}
		else
		{
			$command = 'push ' . $remote . ' ' . $branch;
		}
		return $this->run($command);
	}

	/**
	 * Fetches the given remote
	 *
	 * @param string $repository the name of the remote to fetch, specify "--all" to fetch all remotes
	 * @return string the response from git
	 */
	public function fetch($repository)
	{
		$this->_branches = NULL;
		return $this->run('fetch ' . $repository);
	}

	/**
	 * Gets the active branch
	 *
	 * @return GitBranch the name of the active branch
	 */
	public function getActiveBranch()
	{
		$this->getBranches();
		return $this->_activeBranch;
	}

	/**
	 * Gets a list of git branches
	 *
	 * @return GitBranch[] an array of git branches
	 */
	public function getBranches()
	{
		if($this->_branches === NULL)
		{
			$this->_branches = array();
			foreach(explode("\n", $this->run('branch')) as $branchName)
			{
				$isActive = FALSE;
				if(substr($branchName, 0, 2) == '* ')
				{
					$isActive = TRUE;
				}
				$branchName = trim($branchName, '* ');
				$branch = new GitBranch($branchName, $this);
				if($isActive)
				{
					$branch->isActive = TRUE;
					$this->_activeBranch = $branch;
				}
				$this->_branches[$branchName] = $branch;
			}
		}
		return $this->_branches;
	}

	/**
	 * Determines whether the repository has a specific branch or not
	 *
	 * @param GitBranch|string $branch a branch instance or the name of a branch
	 * @return boolean true if branch exists
	 */
	public function hasBranch($branch)
	{
		if($branch instanceof GitBranch)
		{
			$branch = $branch->name;
		}

		$branches = $this->getBranches();
		return isset($branches[$branch]);
	}

	/**
	 * Creates a branch with the given name
	 *
	 * @param string $branchName the branch name
	 * @return string the response from git
	 */
	public function createBranch($branchName)
	{
		$command = 'branch ' . $branchName;
		$this->_branches = NULL;
		return $this->run($command);
	}

	/**
	 * Deletes the local branch with the given name
	 *
	 * @param string $branchName the branch name
	 * @param boolean $force whether to force the delete
	 * @return string the response from git
	 */
	public function deleteBranch($branchName, $force = FALSE)
	{
		$command = 'branch ' . ($force ? '-D ' : '-d ') . $branchName;
		$this->_branches = NULL;
		return $this->run($command);
	}

	/**
	 * Gets a commit by its hash
	 *
	 * @param string $hash 40 chararcter commit hash of the commit
	 * @return GitCommit|null
	 */
	public function getCommit($hash)
	{
		if(strlen($hash) < 40)
		{
			throw new GitException('Abbreviated commit hashes are not supported yet.');
		}

		if(!isset($this->_commits[$hash]))
		{
			if($this->hasCommit($hash))
			{
				$commit = new GitCommit($hash, $this);
				$this->_commits[$hash] = $commit;
			}
			else
			{
				return NULL;
			}
		}

		return $this->_commits[$hash];
	}

	public function hasCommit($hash)
	{
		return true;
		//@todo
		//$response = $this->run('rev-list ' . $hash);
	}

	/**
	 * Gets a list of tags in this branch
	 *
	 * @return GitTag[] the list of tags
	 */
	public function getTags()
	{
		if($this->_tags !== NULL)
		{
			return $this->_tags;
		}

		$this->_tags = array();
		foreach(explode("\n", $this->run('tag')) as $tagName)
		{
			$tagName = trim($tagName);
			if($tagName != '')
			{
				$this->_tags[$tagName] = new GitTag($tagName, $this);
			}
		}
		return $this->_tags;
	}

	/**
	 * Gets a tag with a specific name
	 *
	 * @param string $name the name of the tag
	 * @return GitTag|null the tag, or null if it doesn't exist
	 */
	public function getTag($name)
	{
		if(!$this->hasTag($name))
		{
			return NULL;
		}
		return $this->_tags[$name];
	}

	/**
	 * Determines whether the repository has a specific tag or not
	 *
	 * @param GitTag|string $tag a tag instance or the name of a tag
	 * @return boolean true if tag exists
	 */
	public function hasTag($tag)
	{
		if($tag instanceof GitTag)
		{
			$tag = $tag->name;
		}
		$tags = $this->getTags();
		return isset($tags[$tag]);
	}

	/**
	 * Adds the given tag to the repository
	 *
	 * @param string $name the name of the new tag
	 * @param string $message tag description
	 * @param string|GitCommit $hash commit object or hash of a commit, if omitted will tag your current HEAD
	 * @return GitTag|null the added tag, or null if the tag wasn't added
	 */
	public function addTag($name, $message, $hash = NULL)
	{
		if($hash instanceof GitCommit)
		{
			$hash = $hash->hash;
		}

		$command = 'tag';
		if(is_string($message))
		{
			$command .= " -m '" . addslashes($message) . "'";
		}

		$command .= ' ' . $name;

		if(is_string($hash))
		{
			$command .= ' ' . $hash;
		}

		$this->run($command);

		$this->_tags = NULL;
		$tag = $this->getTag($name);

		return $tag;
	}

	/**
	 * Removes a particular tag from the repository
	 *
	 * @param GitTag|string $tag the tag instance or name of the tag to remove
	 * @return boolean true if removal succeeded
	 */
	public function removeTag($tag)
	{
		if($tag instanceof GitTag)
		{
			$tag = $tag->name;
		}
		if(!$this->hasTag($tag))
		{
			return FALSE;
		}
		$command = 'tag -d ' . $tag;
		$this->run($command);
		$this->_tags = NULL;
		return TRUE;
	}

	/**
	 * Gets an array of remote repositories
	 *
	 * @return GitRemote[] an array of remote repositories
	 */
	public function getRemotes()
	{
		if($this->_remotes === NULL)
		{
			$this->_remotes = array();
			$command = 'remote -v';
			$response = explode("\n", $this->run($command));

			foreach($response as $line)
			{
				if(preg_match('/(\w+)\s+(.*) \((fetch|push)\)/', $line, $matches))
				{
					if(!isset($this->_remotes[$matches[1]]))
					{
						$this->_remotes[$matches[1]] = new GitRemote($matches[1], $this);
					}
					$remote = $this->_remotes[$matches[1]];
					if($matches[3] == 'fetch')
					{
						$remote->fetchUrl = $matches[2];
					}
					else
					{
						$remote->pushUrl = $matches[2];
					}
				}
			}
		}
		return $this->_remotes;
	}

	/**
	 * Gets a remote repositories.
	 *
	 * @see GitRepository::$defaultRemote
	 * @return GitRemote[] an remote repositories
	 */
	public function getRemote($remote = NULL)
	{
		$remote = is_string($remote) ? $remote : $this->defaultRemote;
		$remotes = $this->getRemotes();
		return isset($remotes[$remote]) ? $remotes[$remote] : NULL;
	}
}
