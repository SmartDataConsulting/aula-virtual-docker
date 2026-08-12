<?php

namespace App\Support;

class Navigation
{
    public static function mainMenu($user = null): array
    {
        $role = strtolower((string) session(AuthSessionKeys::USER_ROLE));

        switch ($role) {
            case 'admin':
            case 'administrador':
            case 'operador':

                $menu = [
                    [
                        'label' => self::coursesLabel($role),
                        'aria_label' => self::isAdministrator($role)
                            ? 'Gestionar todos los cursos'
                            : 'Gestionar mis cursos',
                        'route' => 'backoffice.courses',
                        'active' => ['backoffice.courses', 'backoffice.courses.*'],
                        'icon' => 'bi bi-mortarboard',
                    ],
                    [
                        'label' => 'Evaluaciones',
                        'route' => 'backoffice.evaluations.index',
                        'active' => ['backoffice.evaluations.*'],
                        'icon' => 'bi bi-clipboard-check',
                    ],
                    [
                        'label' => 'Asistencia',
                        'route' => 'backoffice.attendance.index',
                        'active' => ['backoffice.attendance.*'],
                        'icon' => 'bi bi-person-check',
                    ],
                    [
                        'label' => 'Encuestas',
                        'route' => 'backoffice.surveys.index',
                        'active' => ['backoffice.surveys.*'],
                        'icon' => 'bi bi-clipboard-data',
                    ],
                    [
                        'label' => 'Calificaciones',
                        'route' => 'backoffice.qualifications.index',
                        'active' => ['backoffice.qualifications.*'],
                        'icon' => 'bi bi-journal-check',
                    ],
                ];

                if (self::isAdministrator($role)) {
                    $menu[] = [
                        'label' => 'Certificados',
                        'route' => 'backoffice.certificates.index',
                        'active' => ['backoffice.certificates.*'],
                        'icon' => 'bi bi-award',
                    ];
                }

                return $menu;

            case 'alumno':
            default:
                return [
                    [
                        'label' => 'Mis Cursos',
                        'route' => 'mis-cursos.index',
                        'params' => ['tab' => 'activos'],
                        'active' => [
                            'mis-cursos.index',
                            'mis-cursos.show',
                            'mis-cursos.announcements.*',
                            'mis-cursos.sessions.*',
                            'mis-cursos.notas.*',
                            'mis-cursos.evaluaciones.*',
                            'mis-cursos.encuestas.*',
                            'mis-cursos.surveys.*',
                            'mis-cursos.survey.*',
                        ],
                        'icon' => 'bi bi-mortarboard',
                    ],
                    [
                        'label' => 'Mis Pagos',
                        'route' => 'mis-cursos.payments.index',
                        'active' => ['mis-cursos.payments.*'],
                        'icon' => 'bi bi-credit-card',
                    ],
                ];
        }
    }

    public static function coursesLabel(?string $role = null): string
    {
        $role = strtolower((string) ($role ?? session(AuthSessionKeys::USER_ROLE)));

        return self::isAdministrator($role) ? 'Cursos' : 'Mis Cursos';
    }

    private static function isAdministrator(string $role): bool
    {
        return in_array($role, ['admin', 'administrador'], true);
    }
}
