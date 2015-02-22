<?php
namespace wcf\system\exporter;
use wbb\data\board\Board;
use wcf\system\importer\ImportHandler;
use wcf\system\WCF;
use wcf\util\PasswordUtil;

/**
 * Exporter for NodeBB.
 * 
 * @author	Tim Duesterhus
 * @copyright	2001-2015 WoltLab GmbH
 * @license	GNU Lesser General Public License <http://opensource.org/licenses/lgpl-license.php>
 * @package	com.woltlab.wcf.exporter
 * @subpackage	system.exporter
 * @category	Community Framework
 */
class NodeBB0xExporter extends AbstractExporter {
	/**
	 * board cache
	 * @var	array
	 */
	protected $boardCache = array();
	
	/**
	 * @see	\wcf\system\exporter\AbstractExporter::$methods
	 */
	protected $methods = array(
		'com.woltlab.wcf.user' => 'Users',
		'com.woltlab.wbb.board' => 'Boards',
		'com.woltlab.wbb.thread' => 'Threads',
		'com.woltlab.wbb.post' => 'Posts',
	);
	
	/**
	 * @see	\wcf\system\exporter\AbstractExporter::$limits
	 */
	protected $limits = array(
		'com.woltlab.wcf.user' => 100
	);
	
	/**
	 * @see	\wcf\system\exporter\IExporter::init()
	 */
	public function init() {
		$this->database = new \Redis();
		$this->database->connect('localhost', 6379);
	}
	
	/**
	 * @see	\wcf\system\exporter\IExporter::getSupportedData()
	 */
	public function getSupportedData() {
		$supportedData = array(
			'com.woltlab.wcf.user' => array(
			),
			'com.woltlab.wbb.board' => array(
			),
		);
		
		return $supportedData;
	}
	
	/**
	 * @see	\wcf\system\exporter\IExporter::validateDatabaseAccess()
	 */
	public function validateDatabaseAccess() {
		parent::validateDatabaseAccess();
		
		$result = $this->database->exists('global');
		if (!$result) {
			throw new SystemException("Cannot find 'global' key in database");
		}
	}
	
	/**
	 * @see	\wcf\system\exporter\IExporter::validateFileAccess()
	 */
	public function validateFileAccess() {
		return true;
	}
	
	/**
	 * @see	\wcf\system\exporter\IExporter::getQueue()
	 */
	public function getQueue() {
		$queue = array();
		
		// user
		if (in_array('com.woltlab.wcf.user', $this->selectedData)) {
			$queue[] = 'com.woltlab.wcf.user';
		}
		
		// board
		if (in_array('com.woltlab.wbb.board', $this->selectedData)) {
			$queue[] = 'com.woltlab.wbb.board';
			$queue[] = 'com.woltlab.wbb.thread';
			$queue[] = 'com.woltlab.wbb.post';
		}
		
		return $queue;
	}
	
	/**
	 * Counts users.
	 */
	public function countUsers() {
		return $this->database->zcard('users:joindate');
	}
	
	/**
	 * Exports users.
	 */
	public function exportUsers($offset, $limit) {
		// prepare password update
		$sql = "UPDATE	wcf".WCF_N."_user
			SET	password = ?
			WHERE	userID = ?";
		$passwordUpdateStatement = WCF::getDB()->prepareStatement($sql);
		
		$userIDs = $this->database->zrange('users:joindate', $offset, $offset + $limit);
		if (!$userIDs) throw new SystemException('Could not fetch userIDs');
		
		foreach ($userIDs as $userID) {
			$row = $this->database->hgetall('user:'.$userID);
			if (!$row) throw new SystemException('Invalid user');
			
			$data = array(
				'username' => $row['username'],
				'password' => '',
				'email' => $row['email'],
				'registrationDate' => intval($row['joindate'] / 1000),
				'banned' => $row['banned'] ? 1 : 0,
				'banReason' => '',
				'lastActivityTime' => intval($row['lastonline'] / 1000),
				'signature' => $row['signature']
			);
			
			$additionalData = array(
				
			);
			
			$newUserID = ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user')->import($row['uid'], $data, $additionalData);
			
			// update password hash
			if ($newUserID) {
				$password = PasswordUtil::getSaltedHash($row['password'], $row['password']);
				$passwordUpdateStatement->execute(array($password, $newUserID));
			}
		}
	}
	
	/**
	 * Counts boards.
	 */
	public function countBoards() {
		return 1;
	}
	
	/**
	 * Exports boards.
	 */
	public function exportBoards($offset, $limit) {
		$boardIDs = $this->database->zrange('categories:cid', 0, -1);
		if (!$boardIDs) throw new SystemException('Could not fetch boardIDs');
		
		$imported = array();
		foreach ($boardIDs as $boardID) {
			$row = $this->database->hgetall('category:'.$boardID);
			if (!$row) throw new SystemException('Invalid board');
			
			$this->boardCache[$row['parentCid']][] = $row;
		}
		
		$this->exportBoardsRecursively();
	}
	
	/**
	 * Exports the boards recursively.
	 */
	protected function exportBoardsRecursively($parentID = 0) {
		if (!isset($this->boardCache[$parentID])) return;
		
		foreach ($this->boardCache[$parentID] as $board) {
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.board')->import($board['cid'], array(
				'parentID' => ($board['parentCid'] ?: null),
				'position' => $board['order'] ?: 0,
				'boardType' => $board['link'] ? Board::TYPE_LINK : Board::TYPE_BOARD,
				'title' => $board['name'],
				'description' => $board['description'],
				'externalURL' => $board['link']
			));
			
			$this->exportBoardsRecursively($board['cid']);
		}
	}
	
	/**
	 * Counts threads.
	 */
	public function countThreads() {
		return $this->database->zcard('topics:tid');
	}
	
	/**
	 * Exports threads.
	 */
	public function exportThreads($offset, $limit) {
		$threadIDs = $this->database->zrange('topics:tid', $offset, $offset + $limit);
		if (!$threadIDs) throw new SystemException('Could not fetch threadIDs');
		
		foreach ($threadIDs as $threadID) {
			$row = $this->database->hgetall('topic:'.$threadID);
			if (!$row) throw new SystemException('Invalid thread');
			
			$data = array(
				'boardID' => $row['cid'],
				'topic' => $row['title'],
				'time' => intval($row['timestamp'] / 1000),
				'userID' => $row['uid'],
				'username' => $this->database->hget('user:'.$row['uid'], 'username'),
				'views' => $row['viewcount'],
				'isSticky' => $row['pinned'],
				'isDisabled' => 0,
				'isClosed' => $row['locked'],
				'isDeleted' => $row['deleted'],
				'deleteTime' => TIME_NOW,
			);
			
			$additionalData = array(
				'tags' => $this->database->smembers('topic:'.$threadID.':tags') ?: array()
			);
			
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.thread')->import($row['tid'], $data, $additionalData);
		}
	}
	
	/**
	 * Counts posts.
	 */
	public function countPosts() {
		return $this->database->zcard('posts:pid');
	}
	
	/**
	 * Exports posts.
	 */
	public function exportPosts($offset, $limit) {
		$postIDs = $this->database->zrange('posts:pid', $offset, $offset + $limit);
		if (!$postIDs) throw new SystemException('Could not fetch postIDs');
		
		foreach ($postIDs as $postID) {
			$row = $this->database->hgetall('post:'.$postID);
			if (!$row) throw new SystemException('Invalid post');
			
			$data = array(
				'threadID' => $row['tid'],
				'userID' => $row['uid'],
				'username' => $this->database->hget('user:'.$row['uid'], 'username'),
				'subject' => '',
				'message' => self::convertMarkdown($row['content']),
				'time' => intval($row['timestamp'] / 1000),
				'isDeleted' => $row['deleted'],
				'deleteTime' => TIME_NOW,
				'editorID' => ($row['editor'] ?: null),
				'editor' => $this->database->hget('user:'.$row['editor'], 'username'),
				'lastEditTime' => intval($row['edited'] / 1000),
				'editCount' => $row['edited'] ? 1 : 0
			);
			
			ImportHandler::getInstance()->getImporter('com.woltlab.wbb.post')->import($row['pid'], $data);
		}
	}
	
	protected static function convertMarkdown($message) {
		return $message;
	}
}
