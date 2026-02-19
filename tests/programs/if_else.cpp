// expect: 0
int main() {
    int x = 10;
    if (x > 5) {
        x = x + 1;
    } else {
        x = x - 1;
    }
    if (x == 11) {
        return 0;
    }
    return 1;
}
