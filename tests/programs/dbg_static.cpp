// expect: 0
// toolchain: true
extern "C" { int printf(const char* fmt, ...); }

int counter(void) {
    static int count = 0;
    count++;
    return count;
}

int main(void) {
    printf("%d %d %d\n", counter(), counter(), counter());
    return 0;
}
