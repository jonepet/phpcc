#ifndef _CPPC_MATH_H
#define _CPPC_MATH_H
#ifdef __cplusplus
extern "C" {
#endif
#define M_PI 3.14159265358979323846
#define M_E 2.71828182845904523536
#define INFINITY (__builtin_inff())
#define NAN (__builtin_nanf(""))
#define HUGE_VAL (__builtin_huge_val())
double sin(double x);
double cos(double x);
double tan(double x);
double asin(double x);
double acos(double x);
double atan(double x);
double atan2(double y, double x);
double exp(double x);
double log(double x);
double log10(double x);
double log2(double x);
double pow(double x, double y);
double sqrt(double x);
double cbrt(double x);
double ceil(double x);
double floor(double x);
double round(double x);
double trunc(double x);
double fabs(double x);
double fmod(double x, double y);
double fmin(double x, double y);
double fmax(double x, double y);
float sinf(float x);
float cosf(float x);
float tanf(float x);
float sqrtf(float x);
float fabsf(float x);
float ceilf(float x);
float floorf(float x);
float roundf(float x);
float fminf(float x, float y);
float fmaxf(float x, float y);
int isnan(double x);
int isinf(double x);
int isfinite(double x);
#ifdef __cplusplus
}
#endif
#endif
