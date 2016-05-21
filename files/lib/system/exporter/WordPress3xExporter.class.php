<?php
namespace wcf\system\exporter;
use wcf\system\database\util\PreparedStatementConditionBuilder;
use wcf\system\importer\ImportHandler;
use wcf\system\WCF;
use wcf\util\StringUtil;

/**
 * Exporter for WordPress 3.x
 * 
 * @author	Marcel Werk
 * @copyright	2001-2016 WoltLab GmbH
 * @license	GNU Lesser General Public License <http://opensource.org/licenses/lgpl-license.php>
 * @package	com.woltlab.wcf.exporter
 * @subpackage	system.exporter
 * @category	Community Framework
 */
class WordPress3xExporter extends AbstractExporter {
	/**
	 * category cache
	 * @var	array
	 */
	protected $categoryCache = [];
	
	/**
	 * @inheritDoc
	 */
	protected $methods = [
		'com.woltlab.wcf.user' => 'Users',
		'com.woltlab.blog.category' => 'BlogCategories',
		'com.woltlab.blog.entry' => 'BlogEntries',
		'com.woltlab.blog.entry.comment' => 'BlogComments',
		'com.woltlab.blog.entry.attachment' => 'BlogAttachments'
	];
	
	/**
	 * @inheritDoc
	 */
	public function getSupportedData() {
		return [
			'com.woltlab.wcf.user' => [
			],
			'com.woltlab.blog.entry' => [
				'com.woltlab.blog.category',
				'com.woltlab.blog.entry.comment',
				'com.woltlab.blog.entry.attachment'
			]
		];
	}
	
	/**
	 * @inheritDoc
	 */
	public function getQueue() {
		$queue = [];
		
		// user
		if (in_array('com.woltlab.wcf.user', $this->selectedData)) {
			$queue[] = 'com.woltlab.wcf.user';
		}
		
		// blog
		if (in_array('com.woltlab.blog.entry', $this->selectedData)) {
			if (in_array('com.woltlab.blog.category', $this->selectedData)) $queue[] = 'com.woltlab.blog.category';
			$queue[] = 'com.woltlab.blog.entry';
			if (in_array('com.woltlab.blog.entry.comment', $this->selectedData)) $queue[] = 'com.woltlab.blog.entry.comment';
			if (in_array('com.woltlab.blog.entry.attachment', $this->selectedData)) $queue[] = 'com.woltlab.blog.entry.attachment';
		}
		
		return $queue;
	}
	
	/**
	 * @inheritDoc
	 */
	public function validateDatabaseAccess() {
		parent::validateDatabaseAccess();
		
		$sql = "SELECT COUNT(*) FROM ".$this->databasePrefix."posts";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute();
	}
	
	/**
	 * @inheritDoc
	 */
	public function validateFileAccess() {
		if (in_array('com.woltlab.blog.entry.attachment', $this->selectedData)) {
			if (empty($this->fileSystemPath) || (!@file_exists($this->fileSystemPath . 'wp-trackback.php'))) return false;
		}
		
		return true;
	}
	
	/**
	 * @inheritDoc
	 */
	public function getDefaultDatabasePrefix() {
		return 'wp_';
	}
	
	/**
	 * Counts users.
	 */
	public function countUsers() {
		return $this->__getMaxID($this->databasePrefix."users", 'ID');
	}
	
	/**
	 * Exports users.
	 * 
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportUsers($offset, $limit) {
		// prepare password update
		$sql = "UPDATE	wcf".WCF_N."_user
			SET	password = ?
			WHERE	userID = ?";
		$passwordUpdateStatement = WCF::getDB()->prepareStatement($sql);
		
		// get users
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."users
			WHERE		ID BETWEEN ? AND ?
			ORDER BY	ID";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([$offset + 1, $offset + $limit]);
		while ($row = $statement->fetchArray()) {
			$data = [
				'username' => $row['user_login'],
				'password' => '',
				'email' => $row['user_email'],
				'registrationDate' => @strtotime($row['user_registered'])
			];
			
			// import user
			$newUserID = ImportHandler::getInstance()->getImporter('com.woltlab.wcf.user')->import($row['ID'], $data);
			
			// update password hash
			if ($newUserID) {
				$passwordUpdateStatement->execute(['phpass:'.$row['user_pass'].':', $newUserID]);
			}
		}
	}
	
	/**
	 * Counts categories.
	 */
	public function countBlogCategories() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."term_taxonomy
			WHERE	taxonomy = ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(['category']);
		$row = $statement->fetchArray();
		return ($row['count'] ? 1 : 0);
	}
	
	/**
	 * Exports categories.
	 * 
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportBlogCategories($offset, $limit) {
		$sql = "SELECT		term_taxonomy.*, term.name
			FROM		".$this->databasePrefix."term_taxonomy term_taxonomy
			LEFT JOIN	".$this->databasePrefix."terms term
			ON		(term.term_id = term_taxonomy.term_id)
			WHERE		term_taxonomy.taxonomy = ?
			ORDER BY	term_taxonomy.parent, term_taxonomy.term_id";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(['category']);
		while ($row = $statement->fetchArray()) {
			$this->categoryCache[$row['parent']][] = $row;
		}
		
		$this->exportBlogCategoriesRecursively();
	}
	
	/**
	 * Exports the categories recursively.
	 * 
	 * @param	integer		$parentID
	 */
	protected function exportBlogCategoriesRecursively($parentID = 0) {
		if (!isset($this->categoryCache[$parentID])) return;
		
		foreach ($this->categoryCache[$parentID] as $category) {
			ImportHandler::getInstance()->getImporter('com.woltlab.blog.category')->import($category['term_id'], [
				'title' => StringUtil::decodeHTML($category['name']),
				'parentCategoryID' => $category['parent'],
				'showOrder' => 0
			]);
				
			$this->exportBlogCategoriesRecursively($category['term_id']);
		}
	}
	
	/**
	 * Counts blog entries.
	 */
	public function countBlogEntries() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."posts
			WHERE	post_type = ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(['post']);
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports blog entries.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportBlogEntries($offset, $limit) {
		// get entry ids
		$entryIDs = [];
		$sql = "SELECT		ID
			FROM		".$this->databasePrefix."posts
			WHERE		post_type = ?
					AND post_status IN (?, ?, ?, ?, ?, ?)
			ORDER BY	ID";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(['post', 'publish', 'pending', 'draft', 'future', 'private', 'trash']);
		while ($row = $statement->fetchArray()) {
			$entryIDs[] = $row['ID'];
		}
		
		// get tags
		$tags = [];
		$conditionBuilder = new PreparedStatementConditionBuilder();
		$conditionBuilder->add('term_taxonomy.term_taxonomy_id = term_relationships.term_taxonomy_id');
		$conditionBuilder->add('term_relationships.object_id IN (?)', [$entryIDs]);
		$conditionBuilder->add('term_taxonomy.taxonomy = ?', ['post_tag']);
		$conditionBuilder->add('term.term_id IS NOT NULL');
		$sql = "SELECT		term.name, term_relationships.object_id
			FROM		".$this->databasePrefix."term_relationships term_relationships,
					".$this->databasePrefix."term_taxonomy term_taxonomy
			LEFT JOIN	".$this->databasePrefix."terms term
			ON		(term.term_id = term_taxonomy.term_id)
			".$conditionBuilder;
		$statement = $this->database->prepareStatement($sql);
		$statement->execute($conditionBuilder->getParameters());
		while ($row = $statement->fetchArray()) {
			if (!isset($tags[$row['object_id']])) $tags[$row['object_id']] = [];
			$tags[$row['object_id']][] = $row['name'];
		}
		
		// get categories
		$categories = [];
		$conditionBuilder = new PreparedStatementConditionBuilder();
		$conditionBuilder->add('term_taxonomy.term_taxonomy_id = term_relationships.term_taxonomy_id');
		$conditionBuilder->add('term_relationships.object_id IN (?)', [$entryIDs]);
		$conditionBuilder->add('term_taxonomy.taxonomy = ?', ['category']);
		$sql = "SELECT		term_taxonomy.term_id, term_relationships.object_id
			FROM		".$this->databasePrefix."term_relationships term_relationships,
					".$this->databasePrefix."term_taxonomy term_taxonomy
			".$conditionBuilder;
		$statement = $this->database->prepareStatement($sql);
		$statement->execute($conditionBuilder->getParameters());
		while ($row = $statement->fetchArray()) {
			if (!isset($categories[$row['object_id']])) $categories[$row['object_id']] = [];
			$categories[$row['object_id']][] = $row['term_id'];
		}
		
		// get entries
		$conditionBuilder = new PreparedStatementConditionBuilder();
		$conditionBuilder->add('post.ID IN (?)', [$entryIDs]);
		
		$sql = "SELECT		post.*, user.user_login
			FROM		".$this->databasePrefix."posts post
			LEFT JOIN	".$this->databasePrefix."users user
			ON		(user.ID = post.post_author)
			".$conditionBuilder;
		$statement = $this->database->prepareStatement($sql);
		$statement->execute($conditionBuilder->getParameters());
		while ($row = $statement->fetchArray()) {
			$additionalData = [];
			if (isset($tags[$row['ID']])) $additionalData['tags'] = $tags[$row['ID']];
			if (isset($categories[$row['ID']])) $additionalData['categories'] = $categories[$row['ID']];
			
			$time = @strtotime($row['post_date_gmt']);
			if (!$time) $time = @strtotime($row['post_date']);
			
			ImportHandler::getInstance()->getImporter('com.woltlab.blog.entry')->import($row['ID'], [
				'userID' => ($row['post_author'] ?: null),
				'username' => ($row['user_login'] ?: ''),
				'subject' => $row['post_title'],
				'message' => self::fixMessage($row['post_content']),
				'time' => $time,
				'comments' => $row['comment_count'],
				'enableSmilies' => 0,
				'enableHtml' => 1,
				'enableBBCodes' => 0,
				'isPublished' => ($row['post_status'] == 'publish' ? 1 : 0),
				'isDeleted' => ($row['post_status'] == 'trash' ? 1 : 0)
			], $additionalData);
		}
	}
	
	/**
	 * Counts blog comments.
	 */
	public function countBlogComments() {
		$sql = "SELECT	COUNT(*) AS count
			FROM	".$this->databasePrefix."comments
			WHERE	comment_approved = ?";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute([1]);
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports blog comments.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportBlogComments($offset, $limit) {
		$sql = "SELECT	comment_ID, comment_parent
			FROM	".$this->databasePrefix."comments
			WHERE	comment_ID = ?";
		$parentCommentStatement = $this->database->prepareStatement($sql, $limit, $offset);
		
		$sql = "SELECT		*
			FROM		".$this->databasePrefix."comments
			WHERE	comment_approved = ?
			ORDER BY	comment_parent, comment_ID";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute([1]);
		while ($row = $statement->fetchArray()) {
			if (!$row['comment_parent']) {
				ImportHandler::getInstance()->getImporter('com.woltlab.blog.entry.comment')->import($row['comment_ID'], [
					'objectID' => $row['comment_post_ID'],
					'userID' => ($row['user_id'] ?: null),
					'username' => $row['comment_author'],
					'message' => StringUtil::decodeHTML($row['comment_content']),
					'time' => @strtotime($row['comment_date_gmt'])
				]);
			}
			else {
				$parentID = $row['comment_parent'];
				
				do {
					$parentCommentStatement->execute([$parentID]);
					$row2 = $parentCommentStatement->fetchArray();
					
					if (!$row2['comment_parent']) {
						ImportHandler::getInstance()->getImporter('com.woltlab.blog.entry.comment.response')->import($row['comment_ID'], [
							'commentID' => $row2['comment_ID'],
							'userID' => ($row['user_id'] ?: null),
							'username' => $row['comment_author'],
							'message' => StringUtil::decodeHTML($row['comment_content']),
							'time' => @strtotime($row['comment_date_gmt'])
						]);
						break;
					}
					$parentID = $row2['comment_parent'];
				}
				while (true);
			}
		}
	}
	

	/**
	 * Counts blog attachments.
	 */
	public function countBlogAttachments() {
		$sql = "SELECT		COUNT(*) AS count
			FROM		".$this->databasePrefix."posts
			WHERE		post_type = ?
					AND post_parent IN (
						SELECT	ID
						FROM	".$this->databasePrefix."posts	
						WHERE	post_type = ?
							AND post_status IN (?, ?, ?, ?, ?, ?)
					)";
		$statement = $this->database->prepareStatement($sql);
		$statement->execute(['attachment', 'post', 'publish', 'pending', 'draft', 'future', 'private', 'trash']);
		$row = $statement->fetchArray();
		return $row['count'];
	}
	
	/**
	 * Exports blog attachments.
	 *
	 * @param	integer		$offset
	 * @param	integer		$limit
	 */
	public function exportBlogAttachments($offset, $limit) {
		$sql = "SELECT		posts.*, postmeta.*
			FROM		".$this->databasePrefix."posts posts
			LEFT JOIN	".$this->databasePrefix."postmeta postmeta
			ON		(postmeta.post_id = posts.ID AND postmeta.meta_key = ?)
			WHERE		post_type = ?
					AND post_parent IN (
						SELECT	ID
						FROM	".$this->databasePrefix."posts
						WHERE	post_type = ?
							AND post_status IN (?, ?, ?, ?, ?, ?)
					)
			ORDER BY	ID";
		$statement = $this->database->prepareStatement($sql, $limit, $offset);
		$statement->execute(['_wp_attached_file', 'attachment', 'post', 'publish', 'pending', 'draft', 'future', 'private', 'trash']);
		while ($row = $statement->fetchArray()) {
			$fileLocation = $this->fileSystemPath.'wp-content/uploads/'.$row['meta_value'];
			
			$isImage = 0;
			if ($row['post_mime_type'] == 'image/jpeg' || $row['post_mime_type'] == 'image/png' || $row['post_mime_type'] == 'image/gif') $isImage = 1;
			
			$time = @strtotime($row['post_date_gmt']);
			if (!$time) $time = @strtotime($row['post_date']);
			
			ImportHandler::getInstance()->getImporter('com.woltlab.blog.entry.attachment')->import($row['meta_id'], [
				'objectID' => $row['post_parent'],
				'userID' => ($row['post_author'] ?: null),
				'filename' => basename($fileLocation),
				'filesize' => filesize($fileLocation),
				'fileType' => $row['post_mime_type'],
				'isImage' => $isImage,
				'downloads' => 0,
				'lastDownloadTime' => 0,
				'uploadTime' => $time,
				'showOrder' => 0
			], ['fileLocation' => $fileLocation]);
		}
	}
	
	/**
	 * Returns message with fixed syntax as used in WCF.
	 * 
	 * @param	string		$string
	 * @return	string
	 */
	private static function fixMessage($string) {
		$string = str_replace("\n", "<br />\n", StringUtil::unifyNewlines($string));
		
		return $string;
	}
}
