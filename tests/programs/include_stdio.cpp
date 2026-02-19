// expect: 0
// toolchain: true
#include <stdio.h>
#include <stdlib.h>

int main() {
    printf("hello %d\n", 42);
    printf("%s %s\n", "foo", "bar");

    void* p = malloc(64);
    if (p != 0) {
        printf("alloc ok\n");
    }
    free(p);

    return 0;
}
