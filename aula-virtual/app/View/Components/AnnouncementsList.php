<?php

namespace App\View\Components;

use Illuminate\View\Component;

class AnnouncementsList extends Component
{
    public $course;
    public $announcements;
    public $session;
    public $mode;

    public function __construct($course, $announcements = [], $session = null, $mode = 'view')
    {
        $this->course = $course;
        $this->announcements = $announcements;
        $this->session = $session;
        $this->mode = $mode;
    }

    public function render()
    {
        return view('components.announcements-list');
    }
}