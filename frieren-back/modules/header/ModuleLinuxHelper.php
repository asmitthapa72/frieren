<?php

namespace frieren\modules\header;

class ModuleLinuxHelper
{
    public static function shutDownHardware()
    {
        \frieren\helper\LinuxHelper::execBackground('systemctl poweroff');
    }

    public static function resetHardware()
    {
        \frieren\helper\LinuxHelper::execBackground('systemctl reboot');
    }
}