<x-announcements-list
    :course="$course"
    :announcements="$session->announcements ?? collect()"
    :session="$session"
    mode="edit" />
