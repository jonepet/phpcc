#ifndef _CPPC_TIME_H
#define _CPPC_TIME_H
#include <stddef.h>
#ifdef __cplusplus
extern "C" {
#endif
typedef long time_t;
typedef long clock_t;
typedef long suseconds_t;
#define CLOCKS_PER_SEC 1000000L
#define TIME_UTC 1
struct timespec {
    time_t tv_sec;
    long   tv_nsec;
};
struct timeval {
    time_t      tv_sec;
    suseconds_t tv_usec;
};
struct tm {
    int tm_sec;
    int tm_min;
    int tm_hour;
    int tm_mday;
    int tm_mon;
    int tm_year;
    int tm_wday;
    int tm_yday;
    int tm_isdst;
};
time_t time(time_t *tloc);
clock_t clock(void);
double difftime(time_t time1, time_t time0);
time_t mktime(struct tm *tm);
struct tm *localtime(const time_t *timep);
struct tm *gmtime(const time_t *timep);
struct tm *localtime_r(const time_t *timep, struct tm *result);
struct tm *gmtime_r(const time_t *timep, struct tm *result);
char *asctime(const struct tm *tm);
char *ctime(const time_t *timep);
size_t strftime(char *s, size_t max, const char *format, const struct tm *tm);
int nanosleep(const struct timespec *req, struct timespec *rem);
int clock_gettime(int clk_id, struct timespec *tp);
int clock_settime(int clk_id, const struct timespec *tp);
#define CLOCK_REALTIME  0
#define CLOCK_MONOTONIC 1
#ifdef __cplusplus
}
#endif
#endif
