<?php

namespace App\View\Components;

use Illuminate\View\Component;

class SessionMaterialsList extends Component
{
    public $materials;
    public $mode;
    public $course;
    public $session;

    public function __construct($materials, $mode = 'student', $course = null, $session = null)
    {
        $this->materials = $materials;
        $this->mode = $mode; // student | admin
        $this->course = $course;
        $this->session = $session;
    }

    public function render()
    {
        return view('components.session-materials-list');
    }
}