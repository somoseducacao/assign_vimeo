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
 * This file contains the definition for the library class for file submission plugin
 *
 * This class provides all the functionality for the new assign module.
 *
 * @package assignsubmission_file
 * @copyright 2012 NetSpot {@link http://www.netspot.com.au}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once($CFG->libdir.'/eventslib.php');
// Required files for vimeo upload
require_once("Vimeo.php");
require_once("Exceptions/ExceptionInterface.php");
require_once("Exceptions/VimeoRequestException.php");
require_once("Exceptions/VimeoUploadException.php");
defined('MOODLE_INTERNAL') || die();

// File areas for file submission assignment.
define('ASSIGNSUBMISSION_FILE_MAXSUMMARYFILES', 5);
define('ASSIGNSUBMISSION_FILE_FILEAREA', 'submission_files');

/**
 * Library class for file submission plugin extending submission plugin base class
 *
 * @package   assignsubmission_file
 * @copyright 2012 NetSpot {@link http://www.netspot.com.au}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assign_submission_file extends assign_submission_plugin {

    /**
     * Get the name of the file submission plugin
     * @return string
     */
    public function get_name() {
        return get_string('file', 'assignsubmission_file');
    }

    /**
     * Get file submission information from the database
     *
     * @param int $submissionid
     * @return mixed
     */
    private function get_file_submission($submissionid) {
        global $DB;
        return $DB->get_record('assignsubmission_file', array('submission'=>$submissionid));
    }

    /**
     * Get the default setting for file submission plugin
     *
     * @param MoodleQuickForm $mform The form to add elements to
     * @return void
     */
    public function get_settings(MoodleQuickForm $mform) {
        global $CFG, $COURSE;

        $defaultmaxfilesubmissions = $this->get_config('maxfilesubmissions');
        $defaultmaxsubmissionsizebytes = $this->get_config('maxsubmissionsizebytes');
        $defaultfiletypes = (string)$this->get_config('filetypeslist');

        $settings = array();
        $options = array();
        for ($i = 1; $i <= get_config('assignsubmission_file', 'maxfiles'); $i++) {
            $options[$i] = $i;
        }

        $name = get_string('maxfilessubmission', 'assignsubmission_file');
        $mform->addElement('select', 'assignsubmission_file_maxfiles', $name, $options);
        $mform->addHelpButton('assignsubmission_file_maxfiles',
                              'maxfilessubmission',
                              'assignsubmission_file');
        $mform->setDefault('assignsubmission_file_maxfiles', $defaultmaxfilesubmissions);
        $mform->disabledIf('assignsubmission_file_maxfiles', 'assignsubmission_file_enabled', 'notchecked');

        $choices = get_max_upload_sizes($CFG->maxbytes,
                                        $COURSE->maxbytes,
                                        get_config('assignsubmission_file', 'maxbytes'));

        $settings[] = array('type' => 'select',
                            'name' => 'maxsubmissionsizebytes',
                            'description' => get_string('maximumsubmissionsize', 'assignsubmission_file'),
                            'options'=> $choices,
                            'default'=> $defaultmaxsubmissionsizebytes);

        $name = get_string('maximumsubmissionsize', 'assignsubmission_file');
        $mform->addElement('select', 'assignsubmission_file_maxsizebytes', $name, $choices);
        $mform->addHelpButton('assignsubmission_file_maxsizebytes',
                              'maximumsubmissionsize',
                              'assignsubmission_file');
        $mform->setDefault('assignsubmission_file_maxsizebytes', $defaultmaxsubmissionsizebytes);
        $mform->disabledIf('assignsubmission_file_maxsizebytes',
                           'assignsubmission_file_enabled',
                           'notchecked');

        $name = get_string('acceptedfiletypes', 'assignsubmission_file');
        $mform->addElement('text', 'assignsubmission_file_filetypes', $name);
        $mform->addHelpButton('assignsubmission_file_filetypes', 'acceptedfiletypes', 'assignsubmission_file');
        $mform->setType('assignsubmission_file_filetypes', PARAM_RAW);
        $mform->setDefault('assignsubmission_file_filetypes', $defaultfiletypes);
        $mform->disabledIf('assignsubmission_file_filetypes', 'assignsubmission_file_enabled', 'notchecked');
        $mform->addFormRule(function ($values, $files) {
            if (empty($values['assignsubmission_file_filetypes'])) {
                return true;
            }
            $nonexistent = $this->get_nonexistent_file_types($values['assignsubmission_file_filetypes']);
            if (empty($nonexistent)) {
                return true;
            } else {
                $a = join(' ', $nonexistent);
                return ["assignsubmission_file_filetypes" => get_string('nonexistentfiletypes', 'assignsubmission_file', $a)];
            }
        });
    }

    /**
     * Save the settings for file submission plugin
     *
     * @param stdClass $data
     * @return bool
     */
    public function save_settings(stdClass $data) {
        $this->set_config('maxfilesubmissions', $data->assignsubmission_file_maxfiles);
        $this->set_config('maxsubmissionsizebytes', $data->assignsubmission_file_maxsizebytes);

        if (!empty($data->assignsubmission_file_filetypes)) {
            $this->set_config('filetypeslist', $data->assignsubmission_file_filetypes);
        } else {
            $this->set_config('filetypeslist', '');
        }

        return true;
    }

    /**
     * File format options
     *
     * @return array
     */
    private function get_file_options() {
        $fileoptions = array('subdirs' => 1,
                                'maxbytes' => $this->get_config('maxsubmissionsizebytes'),
                                'maxfiles' => $this->get_config('maxfilesubmissions'),
                                'accepted_types' => $this->get_accepted_types(),
                                'return_types' => (FILE_INTERNAL | FILE_CONTROLLED_LINK));
        if ($fileoptions['maxbytes'] == 0) {
            // Use module default.
            $fileoptions['maxbytes'] = get_config('assignsubmission_file', 'maxbytes');
        }
        return $fileoptions;
    }

    /**
     * Add elements to submission form
     *
     * @param mixed $submission stdClass|null
     * @param MoodleQuickForm $mform
     * @param stdClass $data
     * @return bool
     */
    public function get_form_elements($submission, MoodleQuickForm $mform, stdClass $data) {
        global $DB;

        if ($this->get_config('maxfilesubmissions') <= 0) {
            return false;
        }
        $lastupload = $DB->get_record('assign_vimeo',array('assign'=>$submission->assignment,'author'=>$DB->get_record('user',array('id'=>$submission->userid))->username));
        $fileoptions = $this->get_file_options();
        $submissionid = $submission ? $submission->id : 0;

        $data = file_prepare_standard_filemanager($data,
                                                  'files',
                                                  $fileoptions,
                                                  $this->assignment->get_context(),
                                                  'assignsubmission_file',
                                                  ASSIGNSUBMISSION_FILE_FILEAREA,
                                                  $submissionid);

        if($lastupload){
            $mform->addElement('static', 'description', 'Última submissão',$lastupload->embed);
            
        }   

        $mform->addElement('filemanager', 'files_filemanager', $this->get_name(), null, $fileoptions);

        if (!empty($this->get_config('filetypeslist'))) {
            $text = html_writer::tag('p', get_string('filesofthesetypes', 'assignsubmission_file'));
            $text .= html_writer::start_tag('ul');

            $typesets = $this->get_configured_typesets();
            foreach ($typesets as $type) {
                $a = new stdClass();
                $extensions = file_get_typegroup('extension', $type);
                $typetext = html_writer::tag('li', $type);
                // Only bother checking if it's a mimetype or group if it has extensions in the group.
                if (!empty($extensions)) {
                    if (strpos($type, '/') !== false) {
                        $a->name = get_mimetype_description($type);
                        $a->extlist = implode(' ', $extensions);
                        $typetext = html_writer::tag('li', get_string('filetypewithexts', 'assignsubmission_file', $a));
                    } else if (get_string_manager()->string_exists("group:$type", 'mimetypes')) {
                        $a->name = get_string("group:$type", 'mimetypes');
                        $a->extlist = implode(' ', $extensions);
                        $typetext = html_writer::tag('li', get_string('filetypewithexts', 'assignsubmission_file', $a));
                    }
                }
                $text .= $typetext;
            }

            $text .= html_writer::end_tag('ul');
            $mform->addElement('static', '', '', $text);
        }

        return true;
    }

    /**
     * Count the number of files
     *
     * @param int $submissionid
     * @param string $area
     * @return int
     */
    private function count_files($submissionid, $area) {
        $fs = get_file_storage();
        $files = $fs->get_area_files($this->assignment->get_context()->id,
                                     'assignsubmission_file',
                                     $area,
                                     $submissionid,
                                     'id',
                                     false);

        return count($files);
    }

    /**
     * Save the files and trigger plagiarism plugin, if enabled,
     * to scan the uploaded files via events trigger
     *
     * @param stdClass $submission
     * @param stdClass $data
     * @return bool
     */
    public function save(stdClass $submission, stdClass $data) {
        global $USER, $DB, $CFG,$COURSE;
        $mime = '';
        $videorecord = new stdClass();

        $fileoptions = $this->get_file_options();

        $data = file_postupdate_standard_filemanager($data,
                                                     'files',
                                                     $fileoptions,
                                                     $this->assignment->get_context(),
                                                     'assignsubmission_file',
                                                     ASSIGNSUBMISSION_FILE_FILEAREA,
                                                     $submission->id);

        $filesubmission = $this->get_file_submission($submission->id);

        // Plagiarism code event trigger when files are uploaded.

        $fs = get_file_storage();
        $files = $fs->get_area_files($this->assignment->get_context()->id,
                                     'assignsubmission_file',
                                     ASSIGNSUBMISSION_FILE_FILEAREA,
                                     $submission->id,
                                     'id',
                                     false);

        $count = $this->count_files($submission->id, ASSIGNSUBMISSION_FILE_FILEAREA);
        // vimeo upload
        foreach ($files as $file) {
            
            $mime = $file->get_mimetype();
            if(strstr($mime, "video/")){
                $vimeo = new \Vimeo\Vimeo('keyHere', 'keyHere', 'keyHere');
                //var_dump($file);
                $nomearquivo = 'atividade_'.$submission->id.'_'.$COURSE->shortname.'_'.$USER->firstname.$USER->lastname; //.id_da_atividade.'_'.$currentgroupname ;
                $file->copy_content_to($CFG->dataroot.'/'.$nomearquivo);
                $uri = $vimeo->upload($CFG->dataroot.'/'.$nomearquivo);
                $response = $vimeo->request($uri, array('name' =>$nomearquivo,'description' => 'none'  , 'privacy.view' =>'users' , 'privacy.add' => true), 'PATCH');

                $vimeo->request('/albums/idAlbumHere'.$uri,array(),'PUT');
                unlink($CFG->dataroot.'/'.$nomearquivo);           
            /*              
            // Prepare file record object
                $fileinfo = array(
                    'component' => 'atividadeeadsubmission_file',
                    'filearea' => atividadeeadSUBMISSION_FILE_FILEAREA,     // usually = table name
                    'itemid' => 0,               // usually = ID of row in table
                    'contextid' => context_module::instance($this->atividadeead->get_course_module()->id)->id, // ID of context
                    'filepath' => '/',           // any path beginning and ending in /
                    'filename' => $file->get_filename()); // any filename
                
                // Get file
                $file = $fs->get_file($fileinfo['contextid'], $fileinfo['component'], $fileinfo['filearea'], 
                        $fileinfo['itemid'], $fileinfo['filepath'], $fileinfo['filename']);
                
                // Delete it if it exists
                if ($file) {
                    $file->delete();
                }else{
                    var_dump($file);
                }                
                */               
                $videorecord->assign =  $submission->assignment;
                $videorecord->author = $USER->username;
                $videorecord->link = $uri;  
                $videorecord->embed = $response['body']['embed']['html'];
                }
            
             
            //echo('<h1>'.$lastid.'</h1>'); 
        } 
        $params = array(
            'context' => context_module::instance($this->assignment->get_course_module()->id),
            'courseid' => $this->assignment->get_course()->id,
            'objectid' => $submission->id,
            'other' => array(
                'content' => '',
                'pathnamehashes' => array_keys($files)
            )
        );
        if (!empty($submission->userid) && ($submission->userid != $USER->id)) {
            $params['relateduserid'] = $submission->userid;
        }
        $event = \assignsubmission_file\event\assessable_uploaded::create($params);
        $event->set_legacy_files($files);
        $event->trigger();

        $groupname = null;
        $groupid = 0;
        // Get the group name as other fields are not transcribed in the logs and this information is important.
        if (empty($submission->userid) && !empty($submission->groupid)) {
            $groupname = $DB->get_field('groups', 'name', array('id' => $submission->groupid), '*', MUST_EXIST);
            $groupid = $submission->groupid;
        } else {
            $params['relateduserid'] = $submission->userid;
        }

        // Unset the objectid and other field from params for use in submission events.
        unset($params['objectid']);
        unset($params['other']);
        $params['other'] = array(
            'submissionid' => $submission->id,
            'submissionattempt' => $submission->attemptnumber,
            'submissionstatus' => $submission->status,
            'filesubmissioncount' => $count,
            'groupid' => $groupid,
            'groupname' => $groupname
        );

        if ($filesubmission) {
            $filesubmission->numfiles = $this->count_files($submission->id,
                                                           ASSIGNSUBMISSION_FILE_FILEAREA);
            $updatestatus = $DB->update_record('assignsubmission_file', $filesubmission);
            $params['objectid'] = $filesubmission->id;
            
            // vimeo update
            if(strstr($mime, "video/")){
                $oldvideo = $DB->get_record('assign_vimeo', array('assign'=>$submission->assignment,'author'=>$USER->username));
                //var_dump($oldvideo);
                if($oldvideo){
                    $oldvideo->link = $uri;
                    $oldvideo->embed = $response['body']['embed']['html'];
                    $lastid = $DB->update_record('assign_vimeo',$oldvideo);
                }else{
                    $lastid = $DB->insert_record('assign_vimeo',$videorecord);
                }
                
            }
            $event = \assignsubmission_file\event\submission_updated::create($params);
            $event->set_assign($this->assignment);
            $event->trigger();
            return $updatestatus;
        } else {
            $filesubmission = new stdClass();
            $filesubmission->numfiles = $this->count_files($submission->id,
                                                           ASSIGNSUBMISSION_FILE_FILEAREA);
            $filesubmission->submission = $submission->id;
            $filesubmission->assignment = $this->assignment->get_instance()->id;
            $filesubmission->id = $DB->insert_record('assignsubmission_file', $filesubmission);
            $params['objectid'] = $filesubmission->id;
            //vimeo insert
            if(strstr($mime, "video/")){
                $lastid = $DB->insert_record('assign_vimeo',$videorecord);
            }
            $event = \assignsubmission_file\event\submission_created::create($params);
            $event->set_assign($this->assignment);
            $event->trigger();
            return $filesubmission->id > 0;
        }
    }

    /**
     * Produce a list of files suitable for export that represent this feedback or submission
     *
     * @param stdClass $submission The submission
     * @param stdClass $user The user record - unused
     * @return array - return an array of files indexed by filename
     */
    public function get_files(stdClass $submission, stdClass $user) {
        $result = array();
        $fs = get_file_storage();

        $files = $fs->get_area_files($this->assignment->get_context()->id,
                                     'assignsubmission_file',
                                     ASSIGNSUBMISSION_FILE_FILEAREA,
                                     $submission->id,
                                     'timemodified',
                                     false);

        foreach ($files as $file) {
            // Do we return the full folder path or just the file name?
            if (isset($submission->exportfullpath) && $submission->exportfullpath == false) {
                $result[$file->get_filename()] = $file;
            } else {
                $result[$file->get_filepath().$file->get_filename()] = $file;
            }
        }
        return $result;
    }

    /**
     * Display the list of files  in the submission status table
     *
     * @param stdClass $submission
     * @param bool $showviewlink Set this to true if the list of files is long
     * @return string
     */
    public function view_summary(stdClass $submission, & $showviewlink) {
        global $DB;
        $count = $this->count_files($submission->id, ASSIGNSUBMISSION_FILE_FILEAREA);

        // Show we show a link to view all files for this plugin?
        $showviewlink = $count > ASSIGNSUBMISSION_FILE_MAXSUMMARYFILES;
        if ($count <= ASSIGNSUBMISSION_FILE_MAXSUMMARYFILES) {
            $uservimeo = $DB->get_record('user',array('id'=>$submission->userid));                                            
            $embed = $DB->get_record('assign_vimeo', array('assign'=>$submission->assignment,'author'=>$uservimeo->username));
             if($embed){
                if($_GET['action'] == 'grading'){
                    return '<i class="fa fa-film" aria-hidden="true"></i>';
                }
                return $embed->embed;
            }
            return $this->assignment->render_area_files('assignsubmission_file',
                                                        ASSIGNSUBMISSION_FILE_FILEAREA,
                                                        $submission->id);
        } else {
            return get_string('countfiles', 'assignsubmission_file', $count);
        }
    }

    /**
     * No full submission view - the summary contains the list of files and that is the whole submission
     *
     * @param stdClass $submission
     * @return string
     */
    public function view(stdClass $submission) {
        return $this->assignment->render_area_files('assignsubmission_file',
                                                    ASSIGNSUBMISSION_FILE_FILEAREA,
                                                    $submission->id);
    }



    /**
     * Return true if this plugin can upgrade an old Moodle 2.2 assignment of this type
     * and version.
     *
     * @param string $type
     * @param int $version
     * @return bool True if upgrade is possible
     */
    public function can_upgrade($type, $version) {

        $uploadsingletype ='uploadsingle';
        $uploadtype ='upload';

        if (($type == $uploadsingletype || $type == $uploadtype) && $version >= 2011112900) {
            return true;
        }
        return false;
    }


    /**
     * Upgrade the settings from the old assignment
     * to the new plugin based one
     *
     * @param context $oldcontext - the old assignment context
     * @param stdClass $oldassignment - the old assignment data record
     * @param string $log record log events here
     * @return bool Was it a success? (false will trigger rollback)
     */
    public function upgrade_settings(context $oldcontext, stdClass $oldassignment, & $log) {
        global $DB;

        if ($oldassignment->assignmenttype == 'uploadsingle') {
            $this->set_config('maxfilesubmissions', 1);
            $this->set_config('maxsubmissionsizebytes', $oldassignment->maxbytes);
            return true;
        } else if ($oldassignment->assignmenttype == 'upload') {
            $this->set_config('maxfilesubmissions', $oldassignment->var1);
            $this->set_config('maxsubmissionsizebytes', $oldassignment->maxbytes);

            // Advanced file upload uses a different setting to do the same thing.
            $DB->set_field('assign',
                           'submissiondrafts',
                           $oldassignment->var4,
                           array('id'=>$this->assignment->get_instance()->id));

            // Convert advanced file upload "hide description before due date" setting.
            $alwaysshow = 0;
            if (!$oldassignment->var3) {
                $alwaysshow = 1;
            }
            $DB->set_field('assign',
                           'alwaysshowdescription',
                           $alwaysshow,
                           array('id'=>$this->assignment->get_instance()->id));
            return true;
        }
    }

    /**
     * Upgrade the submission from the old assignment to the new one
     *
     * @param context $oldcontext The context of the old assignment
     * @param stdClass $oldassignment The data record for the old oldassignment
     * @param stdClass $oldsubmission The data record for the old submission
     * @param stdClass $submission The data record for the new submission
     * @param string $log Record upgrade messages in the log
     * @return bool true or false - false will trigger a rollback
     */
    public function upgrade(context $oldcontext,
                            stdClass $oldassignment,
                            stdClass $oldsubmission,
                            stdClass $submission,
                            & $log) {
        global $DB;

        $filesubmission = new stdClass();

        $filesubmission->numfiles = $oldsubmission->numfiles;
        $filesubmission->submission = $submission->id;
        $filesubmission->assignment = $this->assignment->get_instance()->id;

        if (!$DB->insert_record('assignsubmission_file', $filesubmission) > 0) {
            $log .= get_string('couldnotconvertsubmission', 'mod_assign', $submission->userid);
            return false;
        }

        // Now copy the area files.
        $this->assignment->copy_area_files_for_upgrade($oldcontext->id,
                                                        'mod_assignment',
                                                        'submission',
                                                        $oldsubmission->id,
                                                        $this->assignment->get_context()->id,
                                                        'assignsubmission_file',
                                                        ASSIGNSUBMISSION_FILE_FILEAREA,
                                                        $submission->id);

        return true;
    }

    /**
     * The assignment has been deleted - cleanup
     *
     * @return bool
     */
    public function delete_instance() {
        global $DB;
        // Will throw exception on failure.
        $DB->delete_records('assignsubmission_file',
                            array('assignment'=>$this->assignment->get_instance()->id));

        return true;
    }

    /**
     * Formatting for log info
     *
     * @param stdClass $submission The submission
     * @return string
     */
    public function format_for_log(stdClass $submission) {
        // Format the info for each submission plugin (will be added to log).
        $filecount = $this->count_files($submission->id, ASSIGNSUBMISSION_FILE_FILEAREA);

        return get_string('numfilesforlog', 'assignsubmission_file', $filecount);
    }

    /**
     * Return true if there are no submission files
     * @param stdClass $submission
     */
    public function is_empty(stdClass $submission) {
        return $this->count_files($submission->id, ASSIGNSUBMISSION_FILE_FILEAREA) == 0;
    }

    /**
     * Determine if a submission is empty
     *
     * This is distinct from is_empty in that it is intended to be used to
     * determine if a submission made before saving is empty.
     *
     * @param stdClass $data The submission data
     * @return bool
     */
    public function submission_is_empty(stdClass $data) {
        $files = file_get_drafarea_files($data->files_filemanager);
        return count($files->list) == 0;
    }

    /**
     * Get file areas returns a list of areas this plugin stores files
     * @return array - An array of fileareas (keys) and descriptions (values)
     */
    public function get_file_areas() {
        return array(ASSIGNSUBMISSION_FILE_FILEAREA=>$this->get_name());
    }

    /**
     * Copy the student's submission from a previous submission. Used when a student opts to base their resubmission
     * on the last submission.
     * @param stdClass $sourcesubmission
     * @param stdClass $destsubmission
     */
    public function copy_submission(stdClass $sourcesubmission, stdClass $destsubmission) {
        global $DB;

        // Copy the files across.
        $contextid = $this->assignment->get_context()->id;
        $fs = get_file_storage();
        $files = $fs->get_area_files($contextid,
                                     'assignsubmission_file',
                                     ASSIGNSUBMISSION_FILE_FILEAREA,
                                     $sourcesubmission->id,
                                     'id',
                                     false);
        foreach ($files as $file) {
            $fieldupdates = array('itemid' => $destsubmission->id);
            $fs->create_file_from_storedfile($fieldupdates, $file);
        }

        // Copy the assignsubmission_file record.
        if ($filesubmission = $this->get_file_submission($sourcesubmission->id)) {
            unset($filesubmission->id);
            $filesubmission->submission = $destsubmission->id;
            $DB->insert_record('assignsubmission_file', $filesubmission);
        }
        return true;
    }

    /**
     * Return a description of external params suitable for uploading a file submission from a webservice.
     *
     * @return external_description|null
     */
    public function get_external_parameters() {
        return array(
            'files_filemanager' => new external_value(
                PARAM_INT,
                'The id of a draft area containing files for this submission.',
                VALUE_OPTIONAL
            )
        );
    }

    /**
     * Return the plugin configs for external functions.
     *
     * @return array the list of settings
     * @since Moodle 3.2
     */
    public function get_config_for_external() {
        global $CFG;

        $configs = $this->get_config();

        // Get a size in bytes.
        if ($configs->maxsubmissionsizebytes == 0) {
            $configs->maxsubmissionsizebytes = get_max_upload_file_size($CFG->maxbytes, $this->assignment->get_course()->maxbytes,
                                                                        get_config('assignsubmission_file', 'maxbytes'));
        }
        return (array) $configs;
    }

    /**
     * Get the type sets configured for this assignment.
     *
     * @return array('groupname', 'mime/type', ...)
     */
    private function get_configured_typesets() {
        $typeslist = (string)$this->get_config('filetypeslist');

        $sets = $this->get_typesets($typeslist);

        return $sets;
    }

    /**
     * Get the type sets passed.
     *
     * @param string $types The space , ; separated list of types
     * @return array('groupname', 'mime/type', ...)
     */
    private function get_typesets($types) {
        $sets = array();
        if (!empty($types)) {
            $sets = preg_split('/[\s,;:"\']+/', $types, null, PREG_SPLIT_NO_EMPTY);
        }
        return $sets;
    }


    /**
     * Return the accepted types list for the file manager component.
     *
     * @return array|string
     */
    private function get_accepted_types() {
        $acceptedtypes = $this->get_configured_typesets();

        if (!empty($acceptedtypes)) {
            return $acceptedtypes;
        }

        return '*';
    }

    /**
     * List the nonexistent file types that need to be removed.
     *
     * @param string $types space , or ; separated types
     * @return array A list of the nonexistent file types.
     */
    private function get_nonexistent_file_types($types) {
        $nonexistent = [];
        foreach ($this->get_typesets($types) as $type) {
            // If there's no extensions under that group, it doesn't exist.
            $extensions = file_get_typegroup('extension', [$type]);
            if (empty($extensions)) {
                $nonexistent[$type] = true;
            }
        }
        return array_keys($nonexistent);
    }
}
