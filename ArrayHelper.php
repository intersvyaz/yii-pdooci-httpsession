<?php
namespace Intersvyaz\HttpSession;

class ArrayHelper
{

    /**
     * Recursive diff between arrays.
     * @param array $a
     * @param array $b
     * @return array
     */
    public static function arrayRecursiveDiff($a, $b)
    {
        $result = [];

        foreach ($a as $key => $value) {
            if (array_key_exists($key, $b)) {
                if (is_array($value)) {
                    $aRecursiveDiff = self::arrayRecursiveDiff($value, $b[$key]);
                    if (count($aRecursiveDiff)) {
                        $result[$key] = $aRecursiveDiff;
                    }
                } else {
                    if (is_object($value) || is_object($b[$key])) {
                        if ($value != $b[$key]) {
                            $result[$key] = $value;
                        }
                    } elseif ($value !== $b[$key]) {
                        $result[$key] = $value;
                    }
                }
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Deletes keys from array A that are in array B but not in array C.
     * @param array $a
     * @param array $b
     * @param array $c
     * @return array
     */
    public static function arrayRemovedRecursiveDiff($a, $b, $c)
    {
        $result = $a;

        foreach ($b as $key => $value) {
            if (is_array($value)) {
                if (array_key_exists($key, $result)) {
                    $result[$key] = self::arrayRemovedRecursiveDiff($result[$key], $value, $c[$key]);
                }
            } elseif (!array_key_exists($key, $c) && is_array($result)) {
                unset($result[$key]);
            }
        }

        return $result;
    }

    /**
     * Merge array from Yii2.
     * @param array $a
     * @param array $b
     * @return array
     */
    public static function merge($a, $b)
    {
        $args = func_get_args();
        $res = array_shift($args);
        while (!empty($args)) {
            foreach (array_shift($args) as $k => $v) {
                if (is_int($k)) {
                    if (array_key_exists($k, $res)) {
                        $res[] = $v;
                    } else {
                        $res[$k] = $v;
                    }
                } elseif (is_array($v) && isset($res[$k]) && is_array($res[$k])) {
                    $res[$k] = self::merge($res[$k], $v);
                } else {
                    $res[$k] = $v;
                }
            }
        }

        return $res;
    }
}
