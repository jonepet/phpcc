#ifndef _CPPC_SYS_TYPES_H
#define _CPPC_SYS_TYPES_H
#include <stddef.h>
#include <stdint.h>
typedef int pid_t;
typedef unsigned int uid_t;
typedef unsigned int gid_t;
typedef long off_t;
typedef long long off64_t;
typedef unsigned int mode_t;
typedef unsigned long ino_t;
typedef unsigned long ino64_t;
typedef unsigned long dev_t;
typedef long nlink_t;
typedef long blksize_t;
typedef long blkcnt_t;
typedef long blkcnt64_t;
typedef long time_t;
typedef long suseconds_t;
typedef long clock_t;
typedef unsigned int socklen_t;
typedef unsigned short sa_family_t;
typedef int clockid_t;
typedef int timer_t;
typedef unsigned long fsblkcnt_t;
typedef unsigned long fsfilcnt_t;
typedef int id_t;
typedef unsigned long useconds_t;
#endif
