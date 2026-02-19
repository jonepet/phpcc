#include <stdio.h>
#include <stdlib.h>
#include <unistd.h>
#include <string.h>

void* __cppc_alloc(size_t size) { return malloc(size); }
void __cppc_free(void* ptr) { free(ptr); }
void __cppc_print_int(long n) { printf("%ld", n); }
void __cppc_print_char(char c) { write(1, &c, 1); }
void __cppc_print_string(const char* s) { write(1, s, strlen(s)); }

void __cxa_pure_virtual(void) {
    write(2, "pure virtual call detected\n", 27);
    _exit(1);
}
