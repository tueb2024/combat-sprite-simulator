/* Macros removed in PHP 8.6 that the SDL extensions still use (force-included with -include). */
#ifndef JUMPHP_PHP86_COMPAT_H
#define JUMPHP_PHP86_COMPAT_H
#include <stddef.h>
#ifndef XtOffsetOf
# define XtOffsetOf(s_type, field) offsetof(s_type, field)
#endif
#ifndef WRONG_PARAM_COUNT
# define ZEND_WRONG_PARAM_COUNT() { zend_wrong_param_count(); return; }
# define WRONG_PARAM_COUNT ZEND_WRONG_PARAM_COUNT()
#endif
/* zval_dtor() and zend_exception_get_default() are gone: same meaning, current names */
#define zval_dtor(zv) zval_ptr_dtor_nogc(zv)
#define zend_exception_get_default() zend_ce_exception
#endif
