// expect: 0
// toolchain: true
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

static int square(int x) { return x * x; }

int get_count(void) {
    static int count = 0;
    count++;
    return count;
}

typedef struct { const char* name; int id; } NamedItem;

void test_array_of_structs() {
    NamedItem items[3];
    items[0].name = "first";  items[0].id = 1;
    items[1].name = "second"; items[1].id = 2;
    items[2].name = "third";  items[2].id = 3;
    for (int i = 0; i < 3; i++) {
        printf("item[%d]=%s\n", i, items[i].name);
    }
}

int main() {
    int a = get_count();
    int b = get_count();
    int c = get_count();
    printf("count=%d%d%d\n", a, b, c);
    printf("sq=%d\n", square(7));
    test_array_of_structs();

    unsigned int x = 0xFF00;
    unsigned int y = 0x0FF0;
    printf("hex=%x %x %x\n", x & y, x | y, x ^ y);

    int data[5];
    int* p = data;
    for (int i = 0; i < 5; i++) { *(p + i) = i * 10; }
    printf("ptr=%d %d %d\n", data[0], data[2], data[4]);

    const char* greeting = "Hello";
    printf("len=%d\n", (int)strlen(greeting));

    return 0;
}
