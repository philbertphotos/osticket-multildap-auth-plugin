<?php

/**
 * Description of plugin
 * @author Joseph Philbert <joe@philbertphotos.com>
 * @license http://opensource.org/licenses/MIT
 * @version 0.1 beta
 */
 
require_once(INCLUDE_DIR . 'class.plugin.php');
require_once(INCLUDE_DIR . 'class.forms.php');

class AssignedAgentConfig extends PluginConfig
{
	function translate()
	{
		if (!method_exists('Plugin', 'translate')) {
			return array(
				function($x) { return $x; },
				function($x, $y, $n) { return $n != 1 ? $y : $x; },
			);
		}
		return Plugin::translate('closed-alert');
	}

	function getDeptList()
	{
		$dept = array();
		$sql = "SELECT `id`, `name` FROM " . TABLE_PREFIX . "department ORDER BY `name`";
		$result = db_query($sql);
		while ($row = db_fetch_array($result)) {
			$dept[$row['id']] = $row['name'];
		}
		return $dept;
	}


    //Get the list of osticket emails
	public function FromMail()
	{
		$frommail = array();
		$sql = 'SELECT email_id,email,name,smtp_active FROM ' . EMAIL_TABLE . ' email ORDER by name';
		if (($res = db_query($sql)) && db_num_rows($res)) {
			while (list($id, $email, $name, $smtp) = db_fetch_row($res)) {
				if ($name) $email = Format::htmlchars("$name <$email>");
				if ($smtp) $email .= ' (' . __('SMTP') . ')';
				$frommail[$id] = $email;
			}
			return $frommail;
		}
	}

	function getOptions()
	{
		list($__, $_N) = self::translate();
		// I'm not 100% sure that closed status has id 3 for everyone.
        // Let's just get all available Statuses and show a selectbox:
		$responses = $staff = $statuses = [];
		$responses = Canned::getCannedResponses();
		$responses['0'] = $__('Send no Reply');

          // Build array of Agents
		$staff[0] = $__('ONLY Send as Ticket\'s Assigned Agent');
		foreach (Staff::objects() as $s) {
			$staff[$s->getId()] = $s->getName();
		}
		
        // Doesn't appear to be a TicketStatus list that I want to use..
		$statuses[0] = '--no changes--';
		foreach (TicketStatus::objects()->values_flat('id', 'name') as $s) {
			list ($id, $name) = $s;
			$statuses[$id] = $name;
		}

		$options = array();

		$this->FromMail();
		$options['from_section_Info'] = new SectionBreakField(
			array(
				'hint' => 'Choose the outgoing Email.',
			)
		);
		$fromlist = array();
		foreach ($this->FromMail() as $from => $id) {
			$fromlist[$from] = $id;
		}

		$options['agent_from'] = new ChoiceField(
			array(
				'label' => 'From',
				'default' => 1,
				'choices' => $fromlist,
			)
		);
		$options['dept_info'] = new SectionBreakField(
			array(
                //'label' => '',
				'hint' => 'Choose one or more departments that will receive alerts when a ticket is assigned.',
			)
		);
		$deptlist = array();
		$deptlist[0] = 'All Departments';
		foreach ($this->getDeptList() as $id => $name) {
			$deptlist[$id] = $name;
		}

		$options['alert_dept'] = new ChoiceField(
			array(
				'label' => 'Departments',
				//'hint' => __('Choose the departments that will recive alerts'),
				'configuration' => array('multiselect' => true),
				'choices' => $deptlist,
				'default' => 0
			)
		);
		$options['alert_section_info'] = new SectionBreakField(
			array(
				'label' => 'Assigned Alert Message',
				//'hint' => 'Update the message/alert that the members will receive.',
			)
		);
		$options['alert-canned'] = new ChoiceField(
			array(
				'label' => $__('Auto-Reply Canned Response'),
				'hint' => $__('Select a canned response to use as the reply, configure in /scp/canned.php'
				),
				'choices' => $responses,
			)
		);
		$options['alert-subject'] = new TextboxField(
			array(
				'label' => 'Subject',
				'hint' => $__('The Subject the person will recive'),
				'default' => "Ticket has been assigned",
				'configuration' => array(
					'size' => 60,
					'length' => 60
				)
			)
		);

		$options['alert-msg'] = new ThreadEntryField(
			array(
				'label' => $__('Alert Message'),
				'default' => 'Hi %{recipient.name}, ticket #%{ticket.number} has been assigned to %{ticket.assigned}<br><em></em>',
				'configuration' => array('attachments' => false),
				'hint' => $__('Put you message here.'),
			)
		);
		$options['alert-account'] = new ChoiceField(
			array(
				'label' => $__('Robot Account'),
				'choices' => $staff,
				'default' => 0,
				'hint' => $__('Select account for sending replies, account can be locked, still works.')
			)
		);
		$options['alert-choice'] = new ChoiceField(
			array(
				'label' => $__('Event Choice'),
				'default' => 0,
				'choices'=>array(
					0 =>'-- '.__('silent').' --',
                    1 =>__('notice'), 
					2 => __('agent')),
				'hint' => $__('Select how you want alert messages displayed in the ticket.')
			));
		$options['alert-status'] = new ChoiceField(
			array(
				'label' => $__('Change Status'),
				'choices' => $statuses,
				'default' => 0,
				'hint' => $__('When we change the ticket, what are we changing the status from? Default is "Open"')
			)
		);

		$options['debug-msg'] = new SectionBreakField(
			array(
				'label' => $__('Debug Mode'),
				'hint' => $__('Turns debugging on or off check the "System Logs" for entires'),
			)
		);
		$options['debug'] = new BooleanField(
			array(
				'label' => $__('Debug'),
				'default' => false,
				'configuration' => array(
					'desc' => $__('Enable debugging')
				)
			)
		);

		return $options;
	}

	function pre_save(&$config, &$errors)
	{
		global $msg;
		if (!$errors) $msg = 'Configuration updated successfully';
		return true;
	}
}
