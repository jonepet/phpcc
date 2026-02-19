// expect: 0
// toolchain: true
extern int printf(const char* fmt, ...);

int add(int a, int b) {
    return a + b;
}

int main(void) {
    printf("sum=%d\n", add(3, 4));
    return 0;
}
