<?php

// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Renderer class for the manual allocation UI is defined here
 *
 * @package   mod-workshop
 * @copyright 2009 David Mudrak <david.mudrak@gmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Manual allocation renderer class
 */
class moodle_mod_workshop_allocation_manual_renderer extends moodle_renderer_base  {

    /** the underlying renderer to use */
    protected $output;

    /** the page we are doing output for */
    protected $page;

    /**
     * Workshop renderer constructor
     *
     * @param mixed $page the page we are doing output for
     * @param mixed $output lower-level renderer, typically moodle_core_renderer
     * @return void
     */
    public function __construct($page, $output) {
        $this->page   = $page;
        $this->output = $output;
    }

    /**
     * Display the table of all current allocations and widgets to modify them
     *
     * @param workshop $workshop workshop API instance
     * @param array $peers prepared array of all allocations
     * @param int $hlauthorid highlight this author
     * @param int $hlreviewerid highlight this reviewer
     * @param object message to display
     * @return string html code
     */
    public function display_allocations(workshop $workshop, &$peers, $hlauthorid=null, $hlreviewerid=null, $msg=null) {

        $wsoutput = $this->page->theme->get_renderer('mod_workshop', $this->page);
        if (empty($peers)) {
            return $wsoutput->status_message((object)array('text' => get_string('nosubmissions', 'workshop')));
        }

        $table              = new html_table();
        $table->set_classes('allocations');
        $table->head        = array(get_string('participantreviewedby', 'workshop'),
                                    get_string('participant', 'workshop'),
                                    get_string('participantrevierof', 'workshop'));
        $table->rowclasses  = array();
        $table->colclasses  = array('reviewedby', 'peer', 'reviewerof');
        $table->data        = array();
        foreach ($peers as $user) {
            $row = array();
            $row[] = $this->reviewers_of_participant($user, $workshop, $peers);
            $row[] = $this->participant($user);
            $row[] = $this->reviewees_of_participant($user, $workshop, $peers);
            $thisrowclasses = array();
            if ($user->id == $hlauthorid) {
                $thisrowclasses[] = 'highlightreviewedby';
            }
            if ($user->id == $hlreviewerid) {
                $thisrowclasses[] = 'highlightreviewerof';
            }
            $table->rowclasses[] = implode(' ', $thisrowclasses);
            $table->data[] = $row;
        }

        return $this->output->container($wsoutput->status_message($msg) . $this->output->table($table), 'manual-allocator');
    }

    /**
     * Returns information about the workshop participant
     *
     * @param stdClass $user participant data
     * @return string HTML code
     */
    protected function participant(stdClass $user) {
        $o  = print_user_picture($user, $this->page->course->id, null, 35, true);
        $o .= fullname($user);
        $o .= $this->output->container_start(array('submission'));
        if (is_null($user->submissionid)) {
            $o .= $this->output->output_tag('span', array('class' => 'info'), get_string('nosubmissionfound', 'workshop'));
        } else {
            $submlink = $this->output->output_tag('a', array('href' => '#'), s($user->submissiontitle));
            $o .= $this->output->container($submlink, array('title'));
            if (is_null($user->submissiongrade)) {
                $o .= $this->output->container(get_string('nogradeyet', 'workshop'), array('grade', 'missing'));
            } else {
                $o .= $this->output->container(s($user->submissiongrade), array('grade')); // TODO calculate grade
            }
        }
        $o .= $this->output->container_end();
        return $o;
    }

    /**
     * Returns information about the current reviewers of the given participant and a selector do add new one
     *
     * @param stdClass $user         participant data
     * @param workshop $workshop workshop record
     * @param array $peers           objects with properties to display picture and fullname
     * @return string html code
     */
    protected function reviewers_of_participant(stdClass $user, workshop $workshop, &$peers) {
        $o = '';
        if (is_null($user->submissionid)) {
            $o .= $this->output->output_tag('span', array('class' => 'info'), get_string('nothingtoreview', 'workshop'));
        } else {
            $options = $this->users_to_menu_options($workshop->get_peer_reviewers(!$workshop->assesswosubmission));
            if (!$workshop->useselfassessment) {
                // students can not review their own submissions in this workshop
                if (isset($options[$user->id])) {
                    unset($options[$user->id]);
                }
            }
            $handler = $this->page->url->out_action() . '&amp;mode=new&amp;of=' . $user->id . '&amp;by=';
            $o .= popup_form($handler, $options, 'addreviewof' . $user->id, '',
                     get_string('chooseuser', 'workshop'), '', '', true, 'self', get_string('addreviewer', 'workshop'));
        }
        $o .= $this->output->output_start_tag('ul', array());
        foreach ($user->reviewedby as $reviewerid => $assessmentid) {
            $o .= $this->output->output_start_tag('li', array());
            $o .= print_user_picture($peers[$reviewerid], $this->page->course->id, null, 16, true);
            $o .= fullname($peers[$reviewerid]);

            $handler = $this->page->url->out_action(array('mode' => 'del', 'what' => $assessmentid));
            $o .= $this->output->output_tag('a', array('href' => $handler), ' X ');

            $o .= $this->output->output_end_tag('li');
        }
        $o .= $this->output->output_end_tag('ul');
        return $o;
    }

    /**
     * Returns information about the current reviewees of the given participant and a selector do add new one
     *
     * @param stdClass $user         participant data
     * @param workshop $workshop workshop record
     * @param array $peers           objects with properties to display picture and fullname
     * @return string html code
     */
    protected function reviewees_of_participant(stdClass $user, workshop $workshop, &$peers) {
        $o = '';
        if (is_null($user->submissionid)) {
            $o .= $this->output->container(get_string('withoutsubmission', 'workshop'), 'info');
        }
        $options = $this->users_to_menu_options($workshop->get_peer_authors());
        if (!$workshop->useselfassessment) {
            // students can not be reviewed by themselves in this workshop
            if (isset($options[$user->id])) {
                unset($options[$user->id]);
            }
        }
        $handler = $this->page->url->out_action() . '&mode=new&amp;by=' . $user->id . '&amp;of=';
        $o .= popup_form($handler, $options, 'addreviewby' . $user->id, '',
                    get_string('chooseuser', 'workshop'), '', '', true, 'self', get_string('addreviewee', 'workshop'));
        $o .= $this->output->output_start_tag('ul', array());
        foreach ($user->reviewerof as $authorid => $assessmentid) {
            $o .= $this->output->output_start_tag('li', array());
            $o .= print_user_picture($peers[$authorid], $this->page->course->id, null, 16, true);
            $o .= fullname($peers[$authorid]);

            // delete
            $handler = $this->page->url->out_action(array('mode' => 'del', 'what' => $assessmentid));
            $o .= $this->output->output_tag('a', array('href' => $handler), ' X ');

            $o .= $this->output->output_end_tag('li');
        }
        $o .= $this->output->output_end_tag('ul');
        return $o;
    }

    /**
     * Given a list of users, returns an array suitable to render the HTML select field
     *
     * @param array $users array of users or array of groups of users
     * @return array of options to be passed to {@see popup_form()}
     */
    protected function users_to_menu_options(&$users) {
        $options = array();
        foreach ($users as $user) {
            $options[$user->id] = fullname($user);
        }
        return $options;
    }

}