// expect: 0
// toolchain: true
extern "C" {
    int printf(const char* fmt, ...);
}

int main() {
    // Comma operator in for-loop update
    int i, j;
    for (i = 0, j = 10; i < 5; i++, j--) {
    }
    printf("i=%d j=%d\n", i, j);

    // Comma operator in expression statement
    int a = 0, b = 0;
    (a = 10, b = 20);
    printf("a=%d b=%d\n", a, b);

    // Comma in for-loop init (multiple declarations)
    int sum = 0;
    for (int x = 0, y = 100; x < 5; x++, y -= 10) {
        sum = sum + x + y;
    }
    printf("sum=%d\n", sum);

    return 0;
}
