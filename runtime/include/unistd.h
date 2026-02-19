#ifndef _CPPC_UNISTD_H
#define _CPPC_UNISTD_H
#include <stddef.h>
#include <stdint.h>
#ifdef __cplusplus
extern "C" {
#endif
typedef int pid_t;
typedef unsigned int uid_t;
typedef unsigned int gid_t;
typedef long off_t;
typedef int mode_t;
#define STDIN_FILENO  0
#define STDOUT_FILENO 1
#define STDERR_FILENO 2
#define SEEK_SET 0
#define SEEK_CUR 1
#define SEEK_END 2
#define F_OK 0
#define X_OK 1
#define W_OK 2
#define R_OK 4
ssize_t read(int fd, void *buf, size_t count);
ssize_t write(int fd, const void *buf, size_t count);
int close(int fd);
off_t lseek(int fd, off_t offset, int whence);
pid_t getpid(void);
pid_t getppid(void);
uid_t getuid(void);
uid_t geteuid(void);
gid_t getgid(void);
gid_t getegid(void);
int setuid(uid_t uid);
int setgid(gid_t gid);
pid_t fork(void);
int execve(const char *filename, char *const argv[], char *const envp[]);
int execvp(const char *file, char *const argv[]);
int execv(const char *path, char *const argv[]);
int execlp(const char *file, const char *arg, ...);
int execl(const char *path, const char *arg, ...);
unsigned int sleep(unsigned int seconds);
int usleep(unsigned int usec);
int dup(int oldfd);
int dup2(int oldfd, int newfd);
int pipe(int pipefd[2]);
int chdir(const char *path);
char *getcwd(char *buf, size_t size);
int unlink(const char *pathname);
int rmdir(const char *pathname);
int mkdir(const char *pathname, mode_t mode);
int access(const char *pathname, int mode);
int link(const char *oldpath, const char *newpath);
int symlink(const char *target, const char *linkpath);
ssize_t readlink(const char *pathname, char *buf, size_t bufsiz);
int truncate(const char *path, off_t length);
int ftruncate(int fd, off_t length);
int fsync(int fd);
int fdatasync(int fd);
long sysconf(int name);
int isatty(int fd);
char *ttyname(int fd);
extern char **environ;
#ifdef __cplusplus
}
#endif
#endif
