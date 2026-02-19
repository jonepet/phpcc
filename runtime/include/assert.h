#ifndef _CPPC_ASSERT_H
#define _CPPC_ASSERT_H
#ifdef __cplusplus
extern "C" {
#endif
extern void abort(void);
#ifdef NDEBUG
#define assert(expr) ((void)0)
#else
#define assert(expr) ((expr) ? (void)0 : abort())
#endif
#ifdef __cplusplus
}
#endif
#endif
