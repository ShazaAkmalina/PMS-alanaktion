<?php

namespace Model;

class Issue extends Base {

	protected $_table_name = "issue";

	/**
	 * Get complete parent list for issue
	 * @return array
	 */
	public function hierarchy() {
		$issues = array();
		$issues[] = $this;
		$parent_id = $this->parent_id;
		while($parent_id) {
			$issue = new Issue();
			$issue->load($parent_id);
			$issues[] = $issue;
			$parent_id = $issue->parent_id;
		}

		return array_reverse($issues);
	}

	/**
	 * Remove messy whitespace from a string
	 * @param  string $string
	 * @return string
	 */
	public static function clean($string) {
		return preg_replace('/(?:(?:\r\n|\r|\n)\s*){2}/s', "\n\n", str_replace("\r\n", "\n", $string));
	}

	/**
	 * Delete without sending notification
	 * @return mixed
	 */
	public function delete() {
		$this->set("deleted_date", now());
		return $this->save(false);
	}

	/**
	 * Log issue update, send notifications
	 * @param  boolean $notify
	 * @return Issue
	 */
	public function save($notify = true) {
		$f3 = \Base::instance();

		// Check if updating or inserting
		if($this->query) {

			// Log update
			$update = new \Model\Issue\Update();
			$update->issue_id = $this->id;
			$update->user_id = $f3->get("user.id");
			$update->created_date = now();
			if($this->exists('update_comment')) {
				$update->comment_id = $this->get('update_comment');
			}
			$update->save();

			$updated = 0;

			// Set hours_total to the hours_remaining value if it's 0 or null
			if($this->get("hours_remaining") && !$this->get("hours_total")) {
				$this->set("hours_total", $this->get("hours_remaining"));
			}

			// Set hours remaining to 0 if the issue has been closed
			if($this->get("closed_date") && $this->get("hours_remaining")) {
				$this->set("hours_remaining", 0);
			}

			// Create a new task if repeating
			if($this->get("closed_date") && $this->get("repeat_cycle") != "none") {

				$repeat_issue = new \Model\Issue();
				$repeat_issue->name = $this->get("name");
				$repeat_issue->type_id = $this->get("type_id");
				$repeat_issue->sprint_id = $this->get("sprint_id");
				$repeat_issue->author_id = $this->get("author_id");
				$repeat_issue->owner_id = $this->get("owner_id");
				$repeat_issue->description = $this->get("description");
				$repeat_issue->repeat_cycle = $this->get("repeat_cycle");
				$repeat_issue->created_date = now();


				// Find a due date in the future
				switch($repeat_issue->repeat_cycle) {
					case 'daily':
						$repeat_issue->due_date = date("Y-m-d", strtotime("tomorrow"));
						break;
					case 'weekly':
						$dow = date("l", strtotime($this->get("due_date")));
						$repeat_issue->due_date = date("Y-m-d", strtotime($this->get("due_date") . " +1 week" ));
						break;
					case 'monthly':
						$day = date("d", strtotime($this->get("due_date")));
						$month = date("m");
						$year = date("Y");
						$repeat_issue->due_date = date("Y-m-d", mktime(0, 0, 0, $month + 1, $day, $year));
						break;
					case 'sprint':
						$sprint = new \Model\Sprint();
						$sprint->load(array("start_date > NOW()"), array('order'=>'start_date'));
						$repeat_issue->due_date =  $sprint->end_date;
						break;
					default:
						$repeat_issue->repeat_cycle == 'none';
				}

				// If the project was in a sprint before, put it in a sprint again.
				if($this->get("sprint_id")) {
					$sprint = new \Model\Sprint();
					$sprint->load(array("id > ? AND end_date > ? AND start_date < ?", $this->get("sprint_id"), $repeat_issue->due_date, $repeat_issue->due_date), array('order'=>'start_date'));
					$repeat_issue->sprint_id = $sprint->id;
				}

				$repeat_issue->save();
				$notification = \Helper\Notification::instance();
				$notification->issue_create($repeat_issue->id);
				$this->set("repeat_cycle", "none");
			}

			// Move all non-project children to same sprint
			$this->resetChildren();

			// Log updated fields
			foreach ($this->fields as $key=>$field) {
				if ($field["changed"] && $field["value"] != $this->get_prev($key)) {
					$update_field = new \Model\Issue\Update\Field();
					$update_field->issue_update_id = $update->id;
					$update_field->field = $key;
					$update_field->old_value = $this->get_prev($key);
					$update_field->new_value = $field["value"];
					$update_field->save();
					$updated ++;
				}
			}

			$issue = parent::save();

			if($updated) {
				// Send notifications
				if($notify) {
					$notification = \Helper\Notification::instance();
					$notification->issue_update($this->get("id"), $update->id);
				}
			} else {
				$update->delete();
			}

		} else {
			$issue = parent::save();
			if($notify) {
				$notification = \Helper\Notification::instance();
				$notification->issue_create($issue->id);
			}
			return $issue;
		}

		return empty($issue) ? parent::save() : $issue;
	}

	/**
	 * Preload custom attributes
	 * @param  string|array $filter
	 * @param  array        $options
	 * @param  integer      $ttl
	 * @return array|FALSE
	 */
	function load($filter=NULL, array $options=NULL, $ttl=0) {
		// Load issue from
		$return = parent::load($filter, $options, $ttl);

		if($this->get("id")) {
			$attr = new \Model\Custom("attribute_value_detail");
			$attrs = $attr->find(array("issue_id = ?", $this->get("id")));
		}

		return $return;
	}

	/**
	 * Duplicate issue and all sub-issues
	 * @return Issue
	 */
	function duplicate() {
		if(!$this->get("id")) {
			return false;
		}

		$f3 = \Base::instance();

		$this->copyto("duplicating_issue");
		$f3->clear("duplicating_issue.id");

		$new_issue = new Issue;
		$new_issue->copyfrom("duplicating_issue");
		$new_issue->save();

		// Run the recursive function to duplicate the complete descendant tree
		$this->_duplicateTree($this->get("id"), $new_issue->id);

		return $new_issue;
	}

	/**
	 * Duplicate a complete issue tree, starting from a duplicated issue created by duplicate()
	 * @param int $id
	 * @param int $new_id
	 */
	protected function _duplicateTree($id, $new_id) {

		// Find all child issues
		$children = $this->find(array("parent_id = ?", $id));
		if(count($children)) {
			$f3 = \Base::instance();
			foreach($children as $child) {

				// Duplicate issue
				$child->copyto("duplicating_issue");
				$f3->clear("duplicating_issue.id");

				$new_child = new Issue;
				$new_child->copyfrom("duplicating_issue");
				$new_child->clear("id");
				$new_child->set("parent_id", $new_id);
				$new_child->save();

				// Duplicate issue's children
				$this->_duplicateTree($child->id, $new_child->id);

			}
		}

	}

	/**
	 * Move all non-project children to same sprint
	 * @return Issue
	 */
	public function resetChildren() {
		if($this->get("sprint_id")) {
			$db = $f3->get("db.instance");
			$db->exec(
				"UPDATE issue SET sprint_id = :sprint WHERE parent_id = :issue AND type_id != :type",
				array(
					"sprint" => $this->get("sprint_id"),
					"issue" => $this->get("id"),
					"type" => $f3->get("issue_type.project")
				)
			);
		}
		return $this;
	}

}
