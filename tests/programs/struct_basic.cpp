// expect: 42
// toolchain: true
struct Point {
    int x;
    int y;
};

int main() {
    struct Point p;
    p.x = 40;
    p.y = 2;
    return p.x + p.y;
}
